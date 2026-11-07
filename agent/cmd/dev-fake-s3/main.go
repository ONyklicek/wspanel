// dev-fake-s3 is the in-memory S3 storage of the tests as a server, for the
// end-to-end test in the dev container (bucket "e2e", access key "E2EKEY").
// Never installed on a real server.
package main

import (
	"flag"
	"log"
	"net/http"

	"wspanel/agent/internal/s3/s3test"
)

func main() {
	addr := flag.String("listen", "127.0.0.1:9000", "address")
	flag.Parse()
	log.Fatal(http.ListenAndServe(*addr, s3test.New("e2e", "E2EKEY")))
}
