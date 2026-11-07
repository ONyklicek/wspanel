// Package s3test is an in-memory S3-compatible storage for tests: it
// understands what the s3 client sends (put, multipart, list v2, get,
// delete) and checks only that a request is signed with the right key id.
package s3test

import (
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strings"
	"sync"
	"time"
)

type Fake struct {
	mu        sync.Mutex
	bucket    string
	accessKey string
	Objects   map[string][]byte
	Modified  map[string]time.Time
	parts     map[string]map[int][]byte
	Parts     map[string]int // parts used by the last multipart upload of a key
	Fail      int            // answer 500 to this many requests first
}

func New(bucket, accessKey string) *Fake {
	return &Fake{bucket: bucket, accessKey: accessKey, Objects: map[string][]byte{}, Modified: map[string]time.Time{}, parts: map[string]map[int][]byte{}, Parts: map[string]int{}}
}

// Keys lists the stored keys, sorted.
func (f *Fake) Keys() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var keys []string
	for k := range f.Objects {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func (f *Fake) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !strings.HasPrefix(r.Header.Get("Authorization"), "AWS4-HMAC-SHA256 Credential="+f.accessKey+"/") {
		w.WriteHeader(403)
		fmt.Fprint(w, "<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>")
		return
	}
	if f.Fail > 0 {
		f.Fail--
		w.WriteHeader(500)
		return
	}
	bucketPath := "/" + f.bucket
	if r.URL.Path != bucketPath && !strings.HasPrefix(r.URL.Path, bucketPath+"/") {
		w.WriteHeader(404)
		fmt.Fprint(w, "<Error><Code>NoSuchBucket</Code><Message>no bucket</Message></Error>")
		return
	}
	key := strings.TrimPrefix(strings.TrimPrefix(r.URL.Path, bucketPath), "/")
	q := r.URL.Query()
	body, _ := io.ReadAll(r.Body)
	switch {
	case r.Method == "GET" && key == "":
		var keys []string
		for k := range f.Objects {
			if strings.HasPrefix(k, q.Get("prefix")) {
				keys = append(keys, k)
			}
		}
		sort.Strings(keys)
		fmt.Fprint(w, "<ListBucketResult>")
		for _, k := range keys {
			fmt.Fprintf(w, "<Contents><Key>%s</Key><Size>%d</Size><LastModified>%s</LastModified></Contents>", k, len(f.Objects[k]), f.Modified[k].UTC().Format("2006-01-02T15:04:05.000Z"))
		}
		fmt.Fprint(w, "<IsTruncated>false</IsTruncated></ListBucketResult>")
	case r.Method == "POST" && q.Has("uploads"):
		f.parts[key] = map[int][]byte{}
		fmt.Fprint(w, "<InitiateMultipartUploadResult><UploadId>U1</UploadId></InitiateMultipartUploadResult>")
	case r.Method == "PUT" && q.Has("partNumber"):
		var n int
		fmt.Sscan(q.Get("partNumber"), &n)
		f.parts[key][n] = body
		w.Header().Set("ETag", fmt.Sprintf(`"etag-%d"`, n))
	case r.Method == "POST" && q.Has("uploadId"):
		var doc struct {
			Parts []struct {
				N int `xml:"PartNumber"`
			} `xml:"Part"`
		}
		_ = xml.Unmarshal(body, &doc)
		var all []byte
		for _, p := range doc.Parts {
			all = append(all, f.parts[key][p.N]...)
		}
		f.Objects[key], f.Modified[key], f.Parts[key] = all, time.Now(), len(doc.Parts)
		fmt.Fprint(w, "<CompleteMultipartUploadResult/>")
	case r.Method == "DELETE" && q.Has("uploadId"):
		delete(f.parts, key)
		w.WriteHeader(204)
	case r.Method == "PUT":
		f.Objects[key], f.Modified[key] = body, time.Now()
	case r.Method == "GET":
		b, ok := f.Objects[key]
		if !ok {
			w.WriteHeader(404)
			fmt.Fprint(w, "<Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>")
			return
		}
		w.Write(b)
	case r.Method == "DELETE":
		delete(f.Objects, key)
		delete(f.Modified, key)
		w.WriteHeader(204)
	}
}
