package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.util.UUID;

import org.junit.jupiter.api.Test;

import ng.atheris.suite.model.SuiteRef;
import ng.atheris.suite.model.UuidV7;
import ng.atheris.suite.security.SuiteSigner;

class ModelTest {

    @Test
    void uuidV7HasVersionVariantAndTimestamp() {
        UUID u = UuidV7.next(0x0193f2c47b1eL);
        assertThat(u.version()).isEqualTo(7);
        assertThat(u.variant()).isEqualTo(2);
        assertThat(u.getMostSignificantBits() >>> 16).isEqualTo(0x0193f2c47b1eL);
        assertThat(UuidV7.next()).isNotEqualTo(UuidV7.next());
    }

    @Test
    void suiteRefParsesFormatsAndValidates() {
        SuiteRef r = SuiteRef.parse("itg:change:CHG-2026-0042");
        assertThat(r.product()).isEqualTo("itg");
        assertThat(r.entity()).isEqualTo("change");
        assertThat(r.code()).isEqualTo("CHG-2026-0042");
        assertThat(r).hasToString("itg:change:CHG-2026-0042");
        assertThat(SuiteRef.parse("isec:threat-model:TM-0008").entity()).isEqualTo("threat-model");
        assertThatThrownBy(() -> SuiteRef.parse("itg:change")).isInstanceOf(IllegalArgumentException.class);
        assertThatThrownBy(() -> SuiteRef.parse("itg:change:")).isInstanceOf(IllegalArgumentException.class);
        assertThatThrownBy(() -> SuiteRef.parse("itg:change:" + "X".repeat(90))).isInstanceOf(IllegalArgumentException.class);
        assertThat(SuiteRef.parse("itg:change:" + "X".repeat(85))).hasToString("itg:change:" + "X".repeat(85));
    }

    @Test
    void signatureFormatMatchesSpec() {
        // HMAC-SHA256("secret", "1700000000.{}") - cross-checkable with any other implementation
        String sig = SuiteSigner.sign("secret", 1700000000L, "{}".getBytes());
        assertThat(sig).startsWith("sha256=").hasSize(7 + 64);
        assertThat(SuiteSigner.matches("secret", 1700000000L, "{}".getBytes(), sig)).isTrue();
        assertThat(SuiteSigner.matches("secret", 1700000001L, "{}".getBytes(), sig)).isFalse();
        assertThat(SuiteSigner.matches("", 1700000000L, "{}".getBytes(), sig)).isFalse();
    }

    @Test
    void contractTogglesDefaultToEnabled() {
        SuiteProperties p = new SuiteProperties();
        p.getContracts().put("c9", false);
        assertThat(p.isEnabled("c9")).isFalse();
        assertThat(p.isEnabled("C9")).isFalse();
        assertThat(p.isEnabled("c1")).isTrue();
    }
}
