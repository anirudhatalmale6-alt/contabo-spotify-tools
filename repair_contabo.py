"""
Repair the 'content-encoding: aws-chunked' header on Contabo objects.

Only touches files that actually have the bad header. Safe to stop with Ctrl-C
and start again - every finished key is written to repaired.txt and skipped
next time.
"""
import boto3, time, threading
from concurrent.futures import ThreadPoolExecutor
from botocore.config import Config

ENDPOINT = "https://eu2.contabostorage.com"
BUCKET   = "audio"
ACCESS   = "YOUR_KEY"
SECRET   = "YOUR_SECRET"

WORKERS  = 16          # 16 threads is about 30/s. Contabo allows 250/s.
DONEFILE = "repaired.txt"
CUTOFF   = None        # leave None to check every file (recommended).
                       # "2026-08-01" = only look at files uploaded on/after
                       # that date. Faster, but anything older is not checked.

try:
    cfg = Config(request_checksum_calculation="when_required",
                 retries={"max_attempts": 5, "mode": "standard"},
                 max_pool_connections=WORKERS * 2)
except TypeError:                     # older botocore, option does not exist
    cfg = Config(retries={"max_attempts": 5, "mode": "standard"},
                 max_pool_connections=WORKERS * 2)

s3 = boto3.client("s3", endpoint_url=ENDPOINT,
                  aws_access_key_id=ACCESS, aws_secret_access_key=SECRET,
                  config=cfg)

# ---------------------------------------------------------------- what is left
done = set()
try:
    with open(DONEFILE) as f:
        done = set(f.read().split())
except FileNotFoundError:
    pass
print(f"{len(done)} keys already done in {DONEFILE}, they will be skipped", flush=True)

print("listing the bucket...", flush=True)
cutoff = None
if CUTOFF:
    import datetime
    cutoff = datetime.datetime.strptime(CUTOFF, "%Y-%m-%d").replace(
        tzinfo=datetime.timezone.utc)

keys, seen = [], 0
for page in s3.get_paginator("list_objects_v2").paginate(Bucket=BUCKET):
    for o in page.get("Contents", []):
        seen += 1
        if o["Key"] in done:
            continue
        if cutoff and o["LastModified"] < cutoff:
            continue
        keys.append(o["Key"])
    print(f"  {seen} listed, {len(keys)} to process", end="\r", flush=True)

TOTAL = len(keys)
print(f"\n{seen} objects in the bucket, {TOTAL} to process\n", flush=True)

# ---------------------------------------------------------------------- worker
lock   = threading.Lock()
log    = open(DONEFILE, "a")
counts = {"checked": 0, "fixed": 0, "ok": 0, "error": 0}
start  = time.time()


def handle(key):
    try:
        head = s3.head_object(Bucket=BUCKET, Key=key)
        if head.get("ContentEncoding") == "aws-chunked":
            s3.copy_object(Bucket=BUCKET, Key=key,
                           CopySource={"Bucket": BUCKET, "Key": key},
                           MetadataDirective="REPLACE",
                           ContentType="audio/mpeg")
            outcome = "fixed"
        else:
            outcome = "ok"
    except Exception as exc:
        with lock:
            counts["checked"] += 1
            counts["error"] += 1
        print(f"ERROR {key} {exc}", flush=True)
        return

    # one lock for the counters AND the file, so lines never interleave
    with lock:
        counts["checked"] += 1
        counts[outcome] += 1
        log.write(key + "\n")
        n = counts["checked"]
        if n % 200 == 0 or n == TOTAL:
            log.flush()
            elapsed = time.time() - start
            rate = n / elapsed if elapsed else 0
            eta = (TOTAL - n) / rate / 60 if rate else 0
            print(f"{n}/{TOTAL}  fixed {counts['fixed']}  already ok {counts['ok']}"
                  f"  errors {counts['error']}  {rate:.1f}/s  eta {eta:.0f} min",
                  flush=True)


with ThreadPoolExecutor(max_workers=WORKERS) as pool:
    list(pool.map(handle, keys))       # list() so nothing is left unconsumed

log.flush()
log.close()
mins = (time.time() - start) / 60
print(f"\nDONE  {counts['checked']} checked, {counts['fixed']} fixed, "
      f"{counts['ok']} already ok, {counts['error']} errors, in {mins:.1f} min")
