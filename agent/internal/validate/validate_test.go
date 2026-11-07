package validate

import (
	"strings"
	"testing"
)

func TestDomain(t *testing.T) {
	good := []string{"example.com", "a.b.c.example.cz", "my-site.test", "xn--bcher-kva.example", "panel.localhost", "x1.io"}
	for _, d := range good {
		if err := Domain(d); err != nil {
			t.Errorf("Domain(%q) = %v, want ok", d, err)
		}
	}
	bad := []string{"", "localhost", "Example.com", "-a.com", "a-.com", "a..com", ".a.com", "a.com.", "a.c", "a.123",
		"a_b.com", "a b.com", "a.com/../etc", "a.com;rm -rf /", "../../etc.com", "a.com\n", strings.Repeat("a", 64) + ".com"}
	for _, d := range bad {
		if err := Domain(d); err == nil {
			t.Errorf("Domain(%q) = ok, want error", d)
		}
	}
}

func TestSiteUser(t *testing.T) {
	if got := SiteUser("example.com"); got != "web_example_com" {
		t.Fatalf("got %q", got)
	}
	if SiteUser("example-com.cz") == SiteUser("example.com.cz") {
		t.Fatal("collision")
	}
	long := SiteUser("a-very-long-subdomain.another-long-part.example.com")
	if len(long) > 32 || !strings.HasPrefix(long, "web_") {
		t.Fatalf("bad long user %q (%d)", long, len(long))
	}
	if long == SiteUser("a-very-long-subdomain.another-long-part.example.org") {
		t.Fatal("long names collide")
	}
}

func TestOneOf(t *testing.T) {
	if OneOf("v", "8.3", []string{"8.3", "8.4"}) != nil {
		t.Fatal("want ok")
	}
	if OneOf("v", "8.3 ", []string{"8.3", "8.4"}) == nil {
		t.Fatal("want error")
	}
}
