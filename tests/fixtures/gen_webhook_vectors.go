package main

// Webhook test vectors generated with a verbatim copy of SignWebhook from
// server/internal/core/webhooks.go (internal packages can't be imported).
import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"strconv"
	"time"
)

// SignWebhook computes the CatzPrint-Signature header value.
func SignWebhook(secret string, ts time.Time, body []byte) string {
	t := strconv.FormatInt(ts.Unix(), 10)
	m := hmac.New(sha256.New, []byte(secret))
	m.Write([]byte(t + "."))
	m.Write(body)
	return "t=" + t + ",v1=" + hex.EncodeToString(m.Sum(nil))
}

func main() {
	type vec struct {
		Name      string `json:"name"`
		Secret    string `json:"secret"`
		Timestamp int64  `json:"timestamp"`
		Body      string `json:"body"`
		Header    string `json:"header"`
	}
	bodies := []vec{
		{Name: "job.printed", Secret: "whsec_9f8e7d6c5b4a39281706f5e4d3c2b1a0", Timestamp: 1791796443,
			Body: `{"created_at":"2026-10-12T09:14:03Z","data":{"job":{"id":"6f1c2a7e-0000-4000-8000-000000000001","status":"printed","reference":"order-1042"}},"org_id":"9b2f0000-0000-4000-8000-000000000000","type":"job.printed"}`},
		{Name: "unicode", Secret: "whsec_unicode_secret", Timestamp: 1791800000,
			Body: `{"created_at":"2026-10-12T10:00:00Z","data":{"job":{"title":"Café € ✓"}},"org_id":"o","type":"job.created"}`},
		{Name: "station.offline", Secret: "whsec_another", Timestamp: 1700000000,
			Body: `{"created_at":"2023-11-14T22:13:20Z","data":{"station":{"id":"s1"}},"org_id":"o2","type":"station.offline"}`},
	}
	for i := range bodies {
		bodies[i].Header = SignWebhook(bodies[i].Secret, time.Unix(bodies[i].Timestamp, 0), []byte(bodies[i].Body))
	}
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	enc.SetEscapeHTML(false)
	_ = enc.Encode(bodies)
}
