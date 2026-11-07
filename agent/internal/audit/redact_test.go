package audit

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestRedact(t *testing.T) {
	out := string(Redact("env.set", json.RawMessage(`{"domain":"a.com","content":"DB_PASSWORD=hunter2\n"}`)))
	if strings.Contains(out, "hunter2") || !strings.Contains(out, `"domain":"a.com"`) || !strings.Contains(out, "<redacted 20 bytes sha256:") {
		t.Fatalf("env.set: %s", out)
	}
	if out := string(Redact("db.access", json.RawMessage(`{"token":"abc","name":"shop"}`))); strings.Contains(out, `"abc"`) {
		t.Fatalf("token kept: %s", out)
	}
	in := json.RawMessage(`{"domain":"a.com"}`)
	if string(Redact("site.create", in)) != string(in) {
		t.Fatal("non-sensitive params changed")
	}
}

func TestSiteTLSKeyIsRedacted(t *testing.T) {
	out := string(Redact("site.tls", json.RawMessage(`{"domain":"a.cz","mode":"custom","cert":"-----BEGIN CERTIFICATE-----","key":"-----BEGIN PRIVATE KEY-----SECRET"}`)))
	if strings.Contains(out, "SECRET") || strings.Contains(out, "BEGIN") || !strings.Contains(out, `"domain":"a.cz"`) {
		t.Fatalf("not redacted: %s", out)
	}
}

func TestEditedFileContentIsRedacted(t *testing.T) {
	out := string(Redact("site.files", json.RawMessage(`{"domain":"a.cz","action":"write","path":"shared/.env","content":"DB_PASSWORD=SECRET"}`)))
	if strings.Contains(out, "SECRET") || !strings.Contains(out, `"path":"shared/.env"`) {
		t.Fatalf("not redacted: %s", out)
	}
}

func TestOffsiteSecretIsRedacted(t *testing.T) {
	out := string(Redact("offsite.set", json.RawMessage(`{"bucket":"b","access_key":"AK","secret_key":"very-secret"}`)))
	if strings.Contains(out, "very-secret") || !strings.Contains(out, `"bucket":"b"`) {
		t.Fatal(out)
	}
}
