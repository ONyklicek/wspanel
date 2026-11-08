package ops

import (
	"crypto/sha512"
	"crypto/subtle"
	"strconv"
	"strings"
)

// sha512CryptMatches tells whether password matches a SHA512-CRYPT hash
// ($6$[rounds=N$]salt$…, the format Dovecot and the panel use), computed as
// Drepper's "Unix crypt using SHA-256 and SHA-512" specifies.
func sha512CryptMatches(password, hash string) bool {
	m := sha512Re.FindString(hash)
	if m == "" || m != hash {
		return false
	}
	rest := strings.TrimPrefix(hash, "$6$")
	rounds, custom := 5000, false
	if r, ok := strings.CutPrefix(rest, "rounds="); ok {
		n, after, _ := strings.Cut(r, "$")
		v, err := strconv.Atoi(n)
		if err != nil {
			return false
		}
		rounds, custom, rest = min(max(v, 1000), 999999999), true, after
	}
	salt, _, _ := strings.Cut(rest, "$")
	if len(salt) > 16 {
		salt = salt[:16]
	}
	want := sha512Crypt([]byte(password), []byte(salt), rounds, custom)
	return subtle.ConstantTimeCompare([]byte(want), []byte(hash)) == 1
}

func sha512Crypt(p, s []byte, rounds int, custom bool) string {
	sum := func(parts ...[]byte) []byte {
		h := sha512.New()
		for _, x := range parts {
			h.Write(x)
		}
		return h.Sum(nil)
	}
	// repeat: the digest repeated (and cut) to n bytes.
	repeat := func(d []byte, n int) []byte {
		out := make([]byte, 0, n)
		for len(out) < n {
			out = append(out, d[:min(len(d), n-len(out))]...)
		}
		return out
	}

	b := sum(p, s, p)
	a := sha512.New()
	a.Write(p)
	a.Write(s)
	a.Write(repeat(b, len(p)))
	for i := len(p); i > 0; i >>= 1 {
		if i&1 == 1 {
			a.Write(b)
		} else {
			a.Write(p)
		}
	}
	prev := a.Sum(nil)

	dp := sha512.New()
	for range len(p) {
		dp.Write(p)
	}
	pp := repeat(dp.Sum(nil), len(p))
	ds := sha512.New()
	for range 16 + int(prev[0]) {
		ds.Write(s)
	}
	sp := repeat(ds.Sum(nil), len(s))

	for r := range rounds {
		c := sha512.New()
		if r&1 == 1 {
			c.Write(pp)
		} else {
			c.Write(prev)
		}
		if r%3 != 0 {
			c.Write(sp)
		}
		if r%7 != 0 {
			c.Write(pp)
		}
		if r&1 == 1 {
			c.Write(prev)
		} else {
			c.Write(pp)
		}
		prev = c.Sum(nil)
	}

	const itoa64 = "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
	var out strings.Builder
	out.WriteString("$6$")
	if custom {
		out.WriteString("rounds=" + strconv.Itoa(rounds) + "$")
	}
	out.Write(s)
	out.WriteByte('$')
	enc := func(b2, b1, b0 byte, n int) {
		w := uint(b2)<<16 | uint(b1)<<8 | uint(b0)
		for range n {
			out.WriteByte(itoa64[w&0x3f])
			w >>= 6
		}
	}
	for i := range 21 {
		// Drepper's byte order: (i, i+21, i+42) rotated by position.
		x, y, z := i, i+21, i+42
		switch i % 3 {
		case 1:
			x, y, z = i+21, i+42, i
		case 2:
			x, y, z = i+42, i, i+21
		}
		enc(prev[x], prev[y], prev[z], 4)
	}
	enc(0, 0, prev[63], 2)
	return out.String()
}
