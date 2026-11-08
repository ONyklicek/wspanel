package ops

import "testing"

func TestSHA512CryptMatchesTheReferenceVectors(t *testing.T) {
	for _, c := range []struct{ password, hash string }{
		// Drepper's specification.
		{"Hello world!", "$6$saltstring$svn8UoSVapNtMuq1ukKS4tPQd8iKwSMHWjl/O817G3uBnIFNjnQJuesI68u4OTLiBFdcbYEdFCoEOfaS35inz1"},
		{"Hello world!", "$6$rounds=10000$saltstringsaltst$OW1/O6BYHV6BcXZu8QVeXbDWra3Oeqh0sbHbbMCVNSnCM/UrjmM0Dp8vOuZeHBy/YTBmSK6H9qs/y3RnOaw5v."},
		// What the panel makes (PHP crypt, 100 000 rounds).
		{"Tajne-heslo-123", "$6$rounds=100000$abcdEFGH12345678$zRumdNkmf70KqN132CvlB6kXVamNydF2fAPB5VRLmJt61PBf42FgX5eXXT8GARDWuqVjODTEM/SIs6/p0SsU1."},
	} {
		if !sha512CryptMatches(c.password, c.hash) {
			t.Errorf("%q does not match %s", c.password, c.hash)
		}
		if sha512CryptMatches(c.password+"x", c.hash) || sha512CryptMatches("", c.hash) {
			t.Errorf("a wrong password matches %s", c.hash)
		}
	}
	if sha512CryptMatches("x", "{CRYPT}*") || sha512CryptMatches("x", "") {
		t.Error("a non-hash matches")
	}
}
