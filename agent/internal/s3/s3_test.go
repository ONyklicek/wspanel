package s3

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/s3/s3test"
)

// The examples of the AWS Signature Version 4 documentation for S3
// ("Examples: Signature Calculations in AWS Signature Version 4").
func TestSignatureMatchesTheAWSExamples(t *testing.T) {
	c, _ := New(Config{Endpoint: "https://s3.amazonaws.com", Region: "us-east-1", Bucket: "examplebucket",
		AccessKey: "AKIAIOSFODNN7EXAMPLE", SecretKey: "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"})
	c.Now = func() time.Time { return time.Date(2013, 5, 24, 0, 0, 0, 0, time.UTC) }

	req, _ := http.NewRequest("GET", "https://examplebucket.s3.amazonaws.com/test.txt", nil)
	req.Header.Set("Range", "bytes=0-9")
	c.sign(req, emptyHash)
	if !strings.HasSuffix(req.Header.Get("Authorization"), "Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41") {
		t.Fatalf("GET object: %s", req.Header.Get("Authorization"))
	}

	req, _ = http.NewRequest("GET", "https://examplebucket.s3.amazonaws.com/", nil)
	req.URL.RawQuery = encodeQuery(url.Values{"max-keys": {"2"}, "prefix": {"J"}})
	c.sign(req, emptyHash)
	if !strings.HasSuffix(req.Header.Get("Authorization"), "Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7") {
		t.Fatalf("list: %s", req.Header.Get("Authorization"))
	}
}

func TestAddressing(t *testing.T) {
	c, _ := New(Config{Endpoint: "https://s3.example.com", Bucket: "b", PathStyle: true})
	if u := c.objectURL("sites/a b/x+y.tar.gz", nil).String(); u != "https://s3.example.com/b/sites/a%20b/x%2By.tar.gz" {
		t.Fatal(u)
	}
	c, _ = New(Config{Endpoint: "https://s3.example.com", Bucket: "b"})
	if u := c.objectURL("k", nil).String(); u != "https://b.s3.example.com/k" {
		t.Fatal(u)
	}
	for _, bad := range []string{"ftp://x", "https://x/path", "https://user:pw@x", "x"} {
		if _, err := New(Config{Endpoint: bad}); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}

func TestPutListGetDeleteAgainstAFakeStorage(t *testing.T) {
	f := s3test.New("bucket", "AK")
	f.Fail = 1
	srv := httptest.NewServer(f)
	defer srv.Close()
	c, err := New(Config{Endpoint: srv.URL, Bucket: "bucket", AccessKey: "AK", SecretKey: "SK", PathStyle: true})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	small := []byte("hello")
	if err := c.Put(ctx, "sites/a/one.tar.gz", bytes.NewReader(small), int64(len(small))); err != nil {
		t.Fatal(err) // the first try got a 500 and was retried
	}
	big := bytes.Repeat([]byte("0123456789abcdef"), (PartSize+1000)/16)
	if err := c.Put(ctx, "sites/a/two.tar.gz", bytes.NewReader(big), int64(len(big))); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(f.Objects["sites/a/two.tar.gz"], big) || f.Parts["sites/a/two.tar.gz"] != 2 {
		t.Fatal("multipart upload not reassembled")
	}
	objs, err := c.List(ctx, "sites/a/")
	if err != nil || len(objs) != 2 || objs[0].Key != "sites/a/one.tar.gz" || objs[0].Size != 5 || objs[0].LastModified.IsZero() {
		t.Fatalf("%+v %v", objs, err)
	}
	rc, _, err := c.Get(ctx, "sites/a/one.tar.gz")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(rc)
	rc.Close()
	if string(b) != "hello" {
		t.Fatal(string(b))
	}
	if err := c.Delete(ctx, "sites/a/one.tar.gz"); err != nil {
		t.Fatal(err)
	}
	_, _, err = c.Get(ctx, "sites/a/one.tar.gz")
	if e, ok := err.(*Error); !ok || e.Code != "NoSuchKey" {
		t.Fatalf("%v", err)
	}
	bad, _ := New(Config{Endpoint: srv.URL, Bucket: "bucket", AccessKey: "WRONG", SecretKey: "SK", PathStyle: true})
	if err := bad.Delete(ctx, "x"); err == nil || !strings.Contains(err.Error(), "AccessDenied") {
		t.Fatal(err)
	}
}
