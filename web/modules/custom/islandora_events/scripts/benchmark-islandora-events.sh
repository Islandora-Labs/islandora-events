#!/usr/bin/env bash

set -euo pipefail

SCRIPT_NAME=$(basename "$0")
ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

DRUSH_CMD='docker compose exec -T drupal drush'
OUTPUT_DIR="${ROOT_DIR}/benchmark-results/$(date -u +%Y%m%dT%H%M%SZ)"
SAMPLE_INTERVAL=5
RUN_LABEL="default"
URL=""
INGEST_SCRIPT=""

usage() {
  cat <<EOF
Usage:
  ${SCRIPT_NAME} --url URL --ingest-script PATH [options] [-- ingest-script-args...]

Required:
  --url URL                 Homepage URL to probe during the run.
  --ingest-script PATH      Path to the ingest script to execute.

Options:
  --drush-cmd CMD           Drush command used to query Drupal.
                            Default: ${DRUSH_CMD}
  --output-dir DIR          Directory for raw samples and summary output.
                            Default: ${OUTPUT_DIR}
  --sample-interval SEC     Sampling interval in seconds. Default: ${SAMPLE_INTERVAL}
  --label LABEL             Run label used in the summary. Default: ${RUN_LABEL}
  --help                    Show this help.

Examples:
  ${SCRIPT_NAME} \\
    --url http://islandora.local/ \\
    --ingest-script ./scripts/run-workbench-ingest.sh

  ${SCRIPT_NAME} \\
    --url http://islandora.local/ \\
    --ingest-script ./scripts/run-workbench-ingest.sh \\
    --label sql-local \\
    --output-dir ./benchmark-results/sql-local
EOF
}

INGEST_ARGS=()

while (($# > 0)); do
  case "$1" in
    --url)
      URL=${2:-}
      shift 2
      ;;
    --ingest-script)
      INGEST_SCRIPT=${2:-}
      shift 2
      ;;
    --drush-cmd)
      DRUSH_CMD=${2:-}
      shift 2
      ;;
    --output-dir)
      OUTPUT_DIR=${2:-}
      shift 2
      ;;
    --sample-interval)
      SAMPLE_INTERVAL=${2:-}
      shift 2
      ;;
    --label)
      RUN_LABEL=${2:-}
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    --)
      shift
      INGEST_ARGS=("$@")
      break
      ;;
    *)
      printf 'Unknown argument: %s\n\n' "$1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${URL}" || -z "${INGEST_SCRIPT}" ]]; then
  usage >&2
  exit 1
fi

if [[ ! -x "${INGEST_SCRIPT}" ]]; then
  printf 'Ingest script is not executable: %s\n' "${INGEST_SCRIPT}" >&2
  exit 1
fi

mkdir -p "${OUTPUT_DIR}"

STOP_FILE="${OUTPUT_DIR}/.stop"
LATENCY_TSV="${OUTPUT_DIR}/homepage-latency.tsv"
HOST_TSV="${OUTPUT_DIR}/host-load.tsv"
DOCKER_TSV="${OUTPUT_DIR}/docker-stats.tsv"
LEDGER_TSV="${OUTPUT_DIR}/ledger-samples.tsv"
SUMMARY_MD="${OUTPUT_DIR}/summary.md"
RUN_META="${OUTPUT_DIR}/run-meta.env"

cat > "${LATENCY_TSV}" <<'EOF'
timestamp_utc	time_total_s	http_code	curl_exit
EOF

cat > "${HOST_TSV}" <<'EOF'
timestamp_utc	load1	load5	load15	mem_total_kb	mem_available_kb
EOF

cat > "${DOCKER_TSV}" <<'EOF'
timestamp_utc	container	cpu_perc	mem_usage	mem_perc	net_io	block_io	pids
EOF

cat > "${LEDGER_TSV}" <<'EOF'
timestamp_utc	total	queued	in_progress	completed	retry_due	failed	abandoned
EOF

run_drush_php() {
  local php_code encoded
  php_code=$1
  encoded=$(printf '%s' "${php_code}" | base64 | tr -d '\n')
  bash -lc "${DRUSH_CMD} php:eval \"eval(base64_decode('${encoded}'));\""
}

ledger_max_id() {
  run_drush_php '$value = \Drupal::database()->select("sm_ledger_event_record", "r")->fields("r", ["id"])->orderBy("id", "DESC")->range(0, 1)->execute()->fetchField(); print (int) ($value ?: 0);'
}

ledger_status_sample() {
  local baseline=$1
  run_drush_php "$(cat <<PHP
\$rows = \Drupal::database()->select('sm_ledger_event_record', 'r')
  ->fields('r', ['status'])
  ->condition('id', ${baseline}, '>')
  ->execute()
  ->fetchCol();
\$counts = [
  'total' => count(\$rows),
  'queued' => 0,
  'in_progress' => 0,
  'completed' => 0,
  'retry_due' => 0,
  'failed' => 0,
  'abandoned' => 0,
];
foreach (\$rows as \$status) {
  if (isset(\$counts[\$status])) {
    \$counts[\$status]++;
  }
}
print implode(\"\\t\", [
  \$counts['total'],
  \$counts['queued'],
  \$counts['in_progress'],
  \$counts['completed'],
  \$counts['retry_due'],
  \$counts['failed'],
  \$counts['abandoned'],
]);
PHP
)"
}

ledger_status_summary_lines() {
  local baseline=$1
  run_drush_php "$(cat <<PHP
\$result = \Drupal::database()->select('sm_ledger_event_record', 'r')
  ->fields('r', ['status'])
  ->condition('id', ${baseline}, '>')
  ->groupBy('status')
  ->addExpression('COUNT(*)', 'count')
  ->orderBy('status', 'ASC')
  ->execute()
  ->fetchAll();
foreach (\$result as \$row) {
  print \$row->status . \"\\t\" . \$row->count . PHP_EOL;
}
PHP
)"
}

sample_homepage_latency() {
  while [[ ! -e "${STOP_FILE}" ]]; do
    local now tmp out curl_rc=0 time_total http_code
    now=$(date -u +%FT%TZ)
    tmp=$(mktemp)
    out=$(curl -o /dev/null -sS -w '%{time_total}\t%{http_code}' "${URL}" 2>"${tmp}") || curl_rc=$?
    curl_rc=${curl_rc:-0}
    time_total=$(printf '%s' "${out:-}" | awk -F'\t' '{print $1}')
    http_code=$(printf '%s' "${out:-}" | awk -F'\t' '{print $2}')
    printf '%s\t%s\t%s\t%s\n' \
      "${now}" \
      "${time_total:-0}" \
      "${http_code:-000}" \
      "${curl_rc}" >> "${LATENCY_TSV}"
    rm -f "${tmp}"
    sleep "${SAMPLE_INTERVAL}"
  done
}

sample_host_load() {
  while [[ ! -e "${STOP_FILE}" ]]; do
    local now load1 load5 load15 mem_total mem_available
    now=$(date -u +%FT%TZ)
    read -r load1 load5 load15 _ < /proc/loadavg
    mem_total=$(awk '/MemTotal:/ {print $2}' /proc/meminfo)
    mem_available=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
      "${now}" "${load1}" "${load5}" "${load15}" "${mem_total}" "${mem_available}" >> "${HOST_TSV}"
    sleep "${SAMPLE_INTERVAL}"
  done
}

sample_docker_stats() {
  if ! command -v docker >/dev/null 2>&1; then
    return 0
  fi

  while [[ ! -e "${STOP_FILE}" ]]; do
    local now
    now=$(date -u +%FT%TZ)
    if docker ps --format '{{.Names}}' >/dev/null 2>&1; then
      docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}\t{{.PIDs}}' \
        $(docker ps --format '{{.Names}}') 2>/dev/null \
        | while IFS=$'\t' read -r name cpu mem_usage mem_perc net_io block_io pids; do
            [[ -n "${name}" ]] || continue
            printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
              "${now}" "${name}" "${cpu}" "${mem_usage}" "${mem_perc}" "${net_io}" "${block_io}" "${pids}" >> "${DOCKER_TSV}"
          done || true
    fi
    sleep "${SAMPLE_INTERVAL}"
  done
}

sample_ledger_counts() {
  local baseline=$1
  while [[ ! -e "${STOP_FILE}" ]]; do
    local now sample
    now=$(date -u +%FT%TZ)
    sample=$(ledger_status_sample "${baseline}")
    printf '%s\t%s\n' "${now}" "${sample}" >> "${LEDGER_TSV}"
    sleep "${SAMPLE_INTERVAL}"
  done
}

cleanup() {
  touch "${STOP_FILE}" 2>/dev/null || true
  jobs -pr | xargs -r kill 2>/dev/null || true
}

trap cleanup EXIT INT TERM

BASELINE_ID=$(ledger_max_id)
START_EPOCH=$(date +%s)

cat > "${RUN_META}" <<EOF
RUN_LABEL=${RUN_LABEL}
URL=${URL}
OUTPUT_DIR=${OUTPUT_DIR}
DRUSH_CMD=${DRUSH_CMD}
SAMPLE_INTERVAL=${SAMPLE_INTERVAL}
BASELINE_ID=${BASELINE_ID}
START_EPOCH=${START_EPOCH}
EOF

sample_homepage_latency &
sample_host_load &
sample_docker_stats &
sample_ledger_counts "${BASELINE_ID}" &

printf 'Starting ingest benchmark "%s"...\n' "${RUN_LABEL}"
if "${INGEST_SCRIPT}" "${INGEST_ARGS[@]}"; then
  INGEST_EXIT=0
else
  INGEST_EXIT=$?
fi
INGEST_END_EPOCH=$(date +%s)

if [[ ${INGEST_EXIT} -ne 0 ]]; then
  printf 'Ingest script failed with exit code %s\n' "${INGEST_EXIT}" >&2
  exit "${INGEST_EXIT}"
fi

printf 'Ingest completed. Waiting for all new ledger rows to leave queued state...\n'

while true; do
  sample=$(ledger_status_sample "${BASELINE_ID}")
  total=$(printf '%s' "${sample}" | awk -F'\t' '{print $1}')
  queued=$(printf '%s' "${sample}" | awk -F'\t' '{print $2}')
  if [[ "${total}" -gt 0 && "${queued}" -eq 0 ]]; then
    break
  fi
  sleep "${SAMPLE_INTERVAL}"
done

END_EPOCH=$(date +%s)
touch "${STOP_FILE}"
sleep 1

INGEST_DURATION=$((INGEST_END_EPOCH - START_EPOCH))
FIRST_PASS_DURATION=$((END_EPOCH - START_EPOCH))

SUCCESS_LATENCIES="${OUTPUT_DIR}/homepage-latency-success.txt"
awk -F'\t' 'NR > 1 && $3 ~ /^2/ && $4 == 0 {print $2}' "${LATENCY_TSV}" > "${SUCCESS_LATENCIES}"

LATENCY_SAMPLES=$(wc -l < "${SUCCESS_LATENCIES}" | awk '{print $1}')
if [[ "${LATENCY_SAMPLES}" -gt 0 ]]; then
  LATENCY_AVG=$(awk '{sum += $1} END {printf "%.3f", sum / NR}' "${SUCCESS_LATENCIES}")
  LATENCY_MIN=$(sort -n "${SUCCESS_LATENCIES}" | head -n 1)
  LATENCY_MAX=$(sort -n "${SUCCESS_LATENCIES}" | tail -n 1)
  LATENCY_P95_INDEX=$(( (LATENCY_SAMPLES * 95 + 99) / 100 ))
  LATENCY_P95=$(sort -n "${SUCCESS_LATENCIES}" | sed -n "${LATENCY_P95_INDEX}p")
else
  LATENCY_AVG="n/a"
  LATENCY_MIN="n/a"
  LATENCY_MAX="n/a"
  LATENCY_P95="n/a"
fi

MAX_LOAD1=$(awk -F'\t' 'NR > 1 {if ($2 > max) max = $2} END {print (max == "" ? "n/a" : max)}' "${HOST_TSV}")
MAX_LOAD5=$(awk -F'\t' 'NR > 1 {if ($3 > max) max = $3} END {print (max == "" ? "n/a" : max)}' "${HOST_TSV}")
MIN_MEM_AVAILABLE_KB=$(awk -F'\t' 'NR == 2 {min = $6} NR > 1 && $6 < min {min = $6} END {print (min == "" ? "n/a" : min)}' "${HOST_TSV}")

FINAL_SAMPLE=$(ledger_status_sample "${BASELINE_ID}")
FINAL_TOTAL=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $1}')
FINAL_QUEUED=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $2}')
FINAL_IN_PROGRESS=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $3}')
FINAL_COMPLETED=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $4}')
FINAL_RETRY_DUE=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $5}')
FINAL_FAILED=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $6}')
FINAL_ABANDONED=$(printf '%s' "${FINAL_SAMPLE}" | awk -F'\t' '{print $7}')

{
  printf '# Islandora Events Benchmark Summary\n\n'
  printf '| Field | Value |\n'
  printf '|---|---|\n'
  printf '| Label | %s |\n' "${RUN_LABEL}"
  printf '| URL | `%s` |\n' "${URL}"
  printf '| Baseline ledger ID | %s |\n' "${BASELINE_ID}"
  printf '| New ledger rows | %s |\n' "${FINAL_TOTAL}"
  printf '| Ingest duration (s) | %s |\n' "${INGEST_DURATION}"
  printf '| Time until all new rows left `queued` (s) | %s |\n' "${FIRST_PASS_DURATION}"
  printf '| Homepage latency avg (s) | %s |\n' "${LATENCY_AVG}"
  printf '| Homepage latency p95 (s) | %s |\n' "${LATENCY_P95}"
  printf '| Homepage latency min/max (s) | %s / %s |\n' "${LATENCY_MIN}" "${LATENCY_MAX}"
  printf '| Host max load1/load5 | %s / %s |\n' "${MAX_LOAD1}" "${MAX_LOAD5}"
  printf '| Host minimum MemAvailable (kB) | %s |\n' "${MIN_MEM_AVAILABLE_KB}"
  printf '| Final queued | %s |\n' "${FINAL_QUEUED}"
  printf '| Final in_progress | %s |\n' "${FINAL_IN_PROGRESS}"
  printf '| Final completed | %s |\n' "${FINAL_COMPLETED}"
  printf '| Final retry_due | %s |\n' "${FINAL_RETRY_DUE}"
  printf '| Final failed | %s |\n' "${FINAL_FAILED}"
  printf '| Final abandoned | %s |\n' "${FINAL_ABANDONED}"
  printf '\n## Final status counts\n\n'
  printf '| Status | Count |\n'
  printf '|---|---:|\n'
  while IFS=$'\t' read -r status count; do
    printf '| %s | %s |\n' "${status}" "${count}"
  done < <(ledger_status_summary_lines "${BASELINE_ID}")
  printf '\n## Raw files\n\n'
  printf '- `homepage-latency.tsv`\n'
  printf '- `host-load.tsv`\n'
  printf '- `docker-stats.tsv`\n'
  printf '- `ledger-samples.tsv`\n'
  printf '- `run-meta.env`\n'
} > "${SUMMARY_MD}"

printf 'Benchmark complete. Summary written to %s\n' "${SUMMARY_MD}"
