package ng.atheris.suite.client;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.Map;

import com.fasterxml.jackson.databind.ObjectMapper;

import org.springframework.http.HttpMethod;
import org.springframework.http.MediaType;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.util.LinkedMultiValueMap;
import org.springframework.util.MultiValueMap;
import org.springframework.util.StreamUtils;
import org.springframework.web.client.ResourceAccessException;
import org.springframework.web.client.RestClient;
import org.springframework.web.util.UriComponentsBuilder;

import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.security.SuiteSigner;

/** Signed HTTP client for the peer product. Events use a longer timeout; queries use {@code suite.query-timeout}. */
public class SuiteClient {

    public record Response(int status, String body) {
        public boolean ok() { return status >= 200 && status < 300; }
    }

    private final SuiteProperties props;
    private final SuiteSigner signer;
    private final ObjectMapper mapper;
    private final RestClient eventClient;
    private final RestClient queryClient;

    public SuiteClient(SuiteProperties props, SuiteSigner signer, ObjectMapper mapper) {
        this.props = props;
        this.signer = signer;
        this.mapper = mapper;
        this.eventClient = build(Duration.ofSeconds(15));
        this.queryClient = build(props.getQueryTimeout());
    }

    private static RestClient build(Duration readTimeout) {
        HttpClient http = HttpClient.newBuilder().version(HttpClient.Version.HTTP_1_1)
                .connectTimeout(Duration.ofSeconds(3)).build();
        JdkClientHttpRequestFactory f = new JdkClientHttpRequestFactory(http);
        f.setReadTimeout(readTimeout);
        return RestClient.builder().requestFactory(f).build();
    }

    /** POSTs a serialized envelope to the peer. Throws {@link SuitePeerUnavailableException} on network errors. */
    public Response postEvent(String envelopeJson) {
        byte[] body = envelopeJson.getBytes(StandardCharsets.UTF_8);
        return exchange(eventClient, HttpMethod.POST, uri("/api/suite/v1/events", Map.of()), body);
    }

    /**
     * Signed GET query against the peer, deserialized into {@code type}.
     *
     * @throws SuitePeerUnavailableException on timeout, connection error or 5xx
     * @throws SuitePeerException on other non-2xx answers
     */
    public <T> T query(String path, Map<String, String> params, Class<T> type) {
        Response r = exchange(queryClient, HttpMethod.GET, uri(path, params), new byte[0]);
        if (r.status() >= 500) {
            throw new SuitePeerUnavailableException("Suite peer answered HTTP " + r.status(), null);
        }
        if (!r.ok()) {
            throw new SuitePeerException(r.status(), r.body());
        }
        try {
            return mapper.readValue(r.body(), type);
        } catch (IOException e) {
            throw new SuitePeerException(502, "unreadable peer response: " + e.getMessage());
        }
    }

    private URI uri(String path, Map<String, String> params) {
        String base = props.getPeer().getUrl();
        if (base == null || base.isBlank()) {
            throw new SuitePeerUnavailableException("suite.peer.url is not configured", null);
        }
        MultiValueMap<String, String> q = new LinkedMultiValueMap<>();
        params.forEach(q::add);
        return UriComponentsBuilder.fromUriString(base.replaceAll("/+$", "") + path)
                .queryParams(q).build().encode().toUri();
    }

    private Response exchange(RestClient client, HttpMethod method, URI uri, byte[] body) {
        try {
            RestClient.RequestBodySpec spec = client.method(method).uri(uri)
                    .headers(h -> signer.headers(body).forEach(h::set));
            if (method == HttpMethod.POST) {
                spec = spec.contentType(MediaType.APPLICATION_JSON).body(body);
            }
            return spec.exchange((req, res) -> new Response(res.getStatusCode().value(),
                    StreamUtils.copyToString(res.getBody(), StandardCharsets.UTF_8)));
        } catch (ResourceAccessException e) {
            throw new SuitePeerUnavailableException("Suite peer unavailable: " + e.getMessage(), e);
        }
    }
}
