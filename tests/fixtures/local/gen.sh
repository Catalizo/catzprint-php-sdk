#!/bin/sh
# Regenerates the TLS fixtures for the local printing tests (valid for 100 years).
#  station.crt/key  self-signed ECDSA P-256, SAN IP 127.0.0.1 (a station's own certificate)
#  ca.crt           a test CA (its key is thrown away)
#  public.crt/key   CA-signed leaf for *.abcdefghij.local.catzprint.com (a "public" station certificate)
# After regenerating, update STATION_FP and PUBLIC_SPKI in tests/Unit/LocalSecurityTest.php:
#   openssl x509 -in station.crt -noout -fingerprint -sha256
#   openssl x509 -in public.crt -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum
set -e
cd "$(dirname "$0")"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout station.key -out station.crt -days 36500 \
  -subj "/CN=CatzPrint Station" -addext "subjectAltName=IP:127.0.0.1"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout ca.key -out ca.crt -days 36500 \
  -subj "/CN=CatzPrint Test CA" -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign"
openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout public.key -out public.csr \
  -subj "/CN=127-0-0-1.abcdefghij.local.catzprint.com"
printf "subjectAltName=DNS:*.abcdefghij.local.catzprint.com\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n" > ext.cnf
openssl x509 -req -in public.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out public.crt -days 36500 -extfile ext.cnf
rm -f public.csr ext.cnf ca.srl ca.key
