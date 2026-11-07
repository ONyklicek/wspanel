// Package protocol defines the JSON wire format between the panel and the agent.
//
// One request per connection: the client writes a single JSON object followed
// by a newline, the agent answers with a single JSON object followed by a
// newline and closes the connection.
package protocol

import "encoding/json"

// MaxRequestBytes caps the size of one request.
const MaxRequestBytes = 1 << 20

type Request struct {
	Op     string          `json:"op"`
	Params json.RawMessage `json:"params,omitempty"`
}

type Response struct {
	OK     bool    `json:"ok"`
	Output any     `json:"output,omitempty"`
	Error  *Error  `json:"error,omitempty"`
	JobID  *string `json:"job_id"`
}

type Error struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

// Error codes.
const (
	CodeBadRequest    = "bad_request"
	CodeUnknownOp     = "unknown_op"
	CodeInvalidParams = "invalid_params"
	CodeConflict      = "conflict"
	CodeNotFound      = "not_found"
	CodeFailed        = "op_failed"
	CodeForbidden     = "forbidden"
)

// OpError is an error that carries a protocol error code.
type OpError struct {
	Code    string
	Message string
}

func (e *OpError) Error() string { return e.Message }

func Invalid(msg string) error   { return &OpError{Code: CodeInvalidParams, Message: msg} }
func Conflict(msg string) error  { return &OpError{Code: CodeConflict, Message: msg} }
func NotFound(msg string) error  { return &OpError{Code: CodeNotFound, Message: msg} }
func Forbidden(msg string) error { return &OpError{Code: CodeForbidden, Message: msg} }
