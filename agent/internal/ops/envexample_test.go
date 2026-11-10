package ops

import (
	"strings"
	"testing"
)

func TestEnvExampleFillsWhatIsMissingAndNeverWhatIsThere(t *testing.T) {
	example := strings.Join([]string{
		"# Laravel",
		"APP_NAME=Intranet",
		"APP_ENV=local",
		"APP_KEY=base64:committedToTheRepo=",
		"APP_DEBUG=true",
		"APP_URL=http://localhost",
		"LOG_LEVEL=debug",
		"DB_CONNECTION=sqlite",
		"DB_PASSWORD=",
		"SESSION_DRIVER=database",
		"AWS_ACCESS_KEY_ID=",
		`VITE_APP_NAME="${APP_NAME}"`,
		`PRIVATE_KEY="-----BEGIN`,
		"-----END-----\"",
		"export PULSE_ENABLED=true",
		"REVERB_APP_SECRET=my-secret",
		"JWT_SECRET=changeme",
		"STRIPE_KEY=",
		"APP_NAME=Twice",
	}, "\n")
	current := "SESSION_DRIVER=redis\nDB_CONNECTION=mysql\n"
	managed := map[string]bool{"DB_CONNECTION": true, "DB_PASSWORD": true}

	lines, keys, secrets := envFromExample(example, current, managed, "devet.example.cz")

	want := []string{
		"APP_NAME=Intranet",
		"APP_ENV=production", // never a development value in production
		"APP_DEBUG=false",
		"APP_URL=https://devet.example.cz",
		"LOG_LEVEL=debug",
		`VITE_APP_NAME="${APP_NAME}"`, // copied as written: it still expands
		"PULSE_ENABLED=true",
	}
	if strings.Join(lines, "\n") != strings.Join(want, "\n") {
		t.Errorf("lines:\n%s\nwant:\n%s", strings.Join(lines, "\n"), strings.Join(want, "\n"))
	}
	if strings.Join(secrets, ",") != "PRIVATE_KEY,REVERB_APP_SECRET,JWT_SECRET" {
		t.Errorf("committed secrets are named for the operator, never copied: %v", secrets)
	}
	for _, k := range keys {
		switch k {
		case "APP_KEY", "REVERB_APP_SECRET", "JWT_SECRET":
			t.Error("a key committed to the repository is public: never copied")
		case "SESSION_DRIVER", "DB_CONNECTION", "DB_PASSWORD", "AWS_ACCESS_KEY_ID", "PRIVATE_KEY":
			t.Errorf("%s: already there, the panel's, empty or cut in half", k)
		}
	}
}
