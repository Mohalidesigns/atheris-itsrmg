package ng.atheris.suite;

import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import org.springframework.boot.context.properties.ConfigurationProperties;

/** Configuration for the suite plumbing (ATH-SUI-001 section 3). Bound from {@code suite.*}. */
@ConfigurationProperties("suite")
public class SuiteProperties {

    private boolean enabled;
    /** Own product code, e.g. "itg" or "isec". */
    private String source;
    /** This product's suite client id (sent as X-Suite-Client). */
    private String clientId;
    private final Peer peer = new Peer();
    private String secret;
    /** Previous secret, accepted until {@link #previousSecretExpiresAt} (24 h rotation window). */
    private String previousSecret;
    private Instant previousSecretExpiresAt;
    private int clockSkewSeconds = 300;
    private Duration queryTimeout = Duration.ofSeconds(3);
    /** Per-contract toggles, keys c1..c15; absent means enabled. */
    private Map<String, Boolean> contracts = new HashMap<>();
    /** Known tenant slugs for the default TenantDirectory. */
    private List<String> tenants = new ArrayList<>();
    private final Outbox outbox = new Outbox();

    public boolean isContractEnabled(String key) {
        return key == null || contracts.getOrDefault(key.toLowerCase(Locale.ROOT), true);
    }

    /** Shorthand for {@link #isContractEnabled(String)}, e.g. {@code isEnabled("c9")}. */
    public boolean isEnabled(String contract) {
        return isContractEnabled(contract);
    }

    public static class Peer {
        private String url;
        private String clientId;
        public String getUrl() { return url; }
        public void setUrl(String url) { this.url = url; }
        public String getClientId() { return clientId; }
        public void setClientId(String clientId) { this.clientId = clientId; }
    }

    public static class Outbox {
        private Duration pollInterval = Duration.ofSeconds(5);
        private int batchSize = 50;
        /** After this long since creation a failing row goes to "failed". */
        private Duration maxAge = Duration.ofHours(72);
        public Duration getPollInterval() { return pollInterval; }
        public void setPollInterval(Duration pollInterval) { this.pollInterval = pollInterval; }
        public int getBatchSize() { return batchSize; }
        public void setBatchSize(int batchSize) { this.batchSize = batchSize; }
        public Duration getMaxAge() { return maxAge; }
        public void setMaxAge(Duration maxAge) { this.maxAge = maxAge; }
    }

    public boolean isEnabled() { return enabled; }
    public void setEnabled(boolean enabled) { this.enabled = enabled; }
    public String getSource() { return source; }
    public void setSource(String source) { this.source = source; }
    public String getClientId() { return clientId; }
    public void setClientId(String clientId) { this.clientId = clientId; }
    public Peer getPeer() { return peer; }
    public String getSecret() { return secret; }
    public void setSecret(String secret) { this.secret = secret; }
    public String getPreviousSecret() { return previousSecret; }
    public void setPreviousSecret(String previousSecret) { this.previousSecret = previousSecret; }
    public Instant getPreviousSecretExpiresAt() { return previousSecretExpiresAt; }
    public void setPreviousSecretExpiresAt(Instant t) { this.previousSecretExpiresAt = t; }
    public int getClockSkewSeconds() { return clockSkewSeconds; }
    public void setClockSkewSeconds(int clockSkewSeconds) { this.clockSkewSeconds = clockSkewSeconds; }
    public Duration getQueryTimeout() { return queryTimeout; }
    public void setQueryTimeout(Duration queryTimeout) { this.queryTimeout = queryTimeout; }
    public Map<String, Boolean> getContracts() { return contracts; }
    public void setContracts(Map<String, Boolean> contracts) { this.contracts = contracts; }
    public List<String> getTenants() { return tenants; }
    public void setTenants(List<String> tenants) { this.tenants = tenants; }
    public Outbox getOutbox() { return outbox; }
}
