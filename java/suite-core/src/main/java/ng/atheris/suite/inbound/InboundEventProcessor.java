package ng.atheris.suite.inbound;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.transaction.annotation.Transactional;

import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.model.Envelope;

/** Inbox + handler dispatch in one transaction: a handler failure rolls back the inbox row. */
public class InboundEventProcessor {

    private final Map<String, SuiteEventHandler> handlers = new HashMap<>();
    private final JdbcClient jdbc;
    private final SuiteProperties props;
    private final Clock clock;

    public InboundEventProcessor(List<SuiteEventHandler> list, JdbcClient jdbc, SuiteProperties props, Clock clock) {
        for (SuiteEventHandler h : list) {
            if (handlers.put(h.type(), h) != null) {
                throw new IllegalStateException("Two SuiteEventHandler beans for type " + h.type());
            }
        }
        this.jdbc = jdbc;
        this.props = props;
        this.clock = clock;
    }

    public SuiteEventHandler handlerFor(String type) {
        return handlers.get(type);
    }

    /** @return "duplicate", "ignored" or "processed" */
    @Transactional
    public String process(Envelope e, SuiteEventHandler handler) {
        OffsetDateTime now = OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
        int inserted = jdbc.sql("insert into suite_inbox (id, type, tenant, received_at) values (:id, :type, :tenant, :now) on conflict (id) do nothing")
                .param("id", e.id()).param("type", e.type()).param("tenant", e.tenant()).param("now", now).update();
        if (inserted == 0) {
            return "duplicate";
        }
        String contract = handler.contract();
        String result = "processed";
        if (contract != null && !props.isContractEnabled(contract)) {
            result = "ignored";
        } else {
            handler.handle(e);
        }
        jdbc.sql("update suite_inbox set processed_at = :now, result = :result where id = :id")
                .param("now", now).param("result", result).param("id", e.id()).update();
        return result;
    }
}
