# Changelog

## 1.1.0

- A finalization that keeps throwing fails the job after `finalize_attempts` (5) tries; a job stuck in finalizing can be cancelled after 5 minutes.
- Staged files are deleted when their job completes without failed units (`keep_artifacts` to keep them).
- `upload()` retries network errors, 5xx, 422 and 429 (4 attempts).
- `GET waiting` and `watchQueue()` take `types`: a helper only wakes for work its worker runs.

## 1.0.0

First public release.
