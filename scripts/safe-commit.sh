#!/usr/bin/env bash
# safe-commit.sh — deterministic half of the commit-work skill.
# Verifies branch, stages the whole tree, unstages sensitive files (BLOCK),
# flags suspicious-but-maybe-legit paths (WARN), and prints a compact report
# the model writes the commit message from.
#
# Output lines:
#   NOT_ON_DEV: <branch>          exit 2 — skill must confirm with user first
#   NOTHING_TO_COMMIT             exit 3
#   BLOCKED: <path> (<rule>)      auto-unstaged, never commit
#   LEAK: <path> (<rule>)         sensitive AND already tracked in HEAD — pre-existing leak
#   WARN: <path> (<reason>)       still staged, needs model judgment
#   STAGED:                       staged summary (full --stat, or grouped by dir when huge)
#   RECENT:                       last 5 commit subjects — style reference for the message
#
# Exit codes: 0 ok · 1 git error · 2 not on dev · 3 nothing to commit
set -u
cd "$(git rev-parse --show-toplevel)" || exit 1

branch=$(git branch --show-current)
if [ "$branch" != "dev" ]; then
	echo "NOT_ON_DEV: $branch"
	exit 2
fi

git add -A >/dev/null || exit 1

if git diff --cached --quiet; then
	echo "NOTHING_TO_COMMIT"
	exit 3
fi

echo "BRANCH: dev"

# ---------------------------------------------------------------------------
# BLOCK tier — mirrors .gitignore offenders + generic key/cred material.
# Prints the rule name on match. Single source of truth for sensitive patterns;
# add new patterns HERE (the old references/sensitive-patterns.md is retired).
# ---------------------------------------------------------------------------
blocked_rule() {
	local p base
	p=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')
	base=${p##*/}
	case "$base" in
		.env.example|.env.environment.example) return 1 ;;
		.env|.env.*|*.env.local|secrets.*.env) echo "env / secret bundle — app creds, API keys"; return 0 ;;
	esac
	case "$p" in
		*dump*.sql|back.sql|*.dump|*.backup|*.sql.gz) echo "DB dump — real password hashes + employee pay and PII"; return 0 ;;
		*.pem|*.key|*.pfx|*.p12|*.crt|*.cer|*.ppk|*.asc|*.gpg) echo "private key / cert material"; return 0 ;;
		*id_rsa*|*id_ed25519*) echo "SSH private key"; return 0 ;;
		*.xlsx|*.xls|*.csv) echo "payroll / PAYE / PII spreadsheet (repo policy: never tracked)"; return 0 ;;
		*.pdf) echo "binary doc (repo policy: never tracked)"; return 0 ;;
		*service-account*.json|*credentials*.json|*gcloud*.json) echo "cloud service creds"; return 0 ;;
		.aws/*|*/.aws/*|.npmrc|*/.npmrc|.pypirc|*/.pypirc|.netrc|*/.netrc|*kubeconfig*) echo "service credentials file"; return 0 ;;
		*.sqlite|*.sqlite3|*.db|*.bak) echo "local DB / backup blob"; return 0 ;;
	esac
	return 1
}

# WARN tier — secret-looking names that are often legit code (password-reset
# views, CSRF token helpers). Stays staged; model reviews.
warn_reason() {
	local p
	p=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')
	case "$p" in
		*secret*|*token*|*apikey*|*api_key*|*password*|*passwd*|*private_key*) echo "secret-looking name"; return 0 ;;
		*.gz|*.zip|*.bz2) echo "archive — may wrap a DB dump"; return 0 ;;
	esac
	return 1
}

# Pass 1: block + leak detection.
git diff --cached --name-only | while IFS= read -r f; do
	rule=$(blocked_rule "$f") || continue
	if git cat-file -e "HEAD:$f" 2>/dev/null; then
		echo "LEAK: $f ($rule) — already tracked in history, flag loudly"
	fi
	git restore --staged -- "$f"
	echo "BLOCKED: $f ($rule)"
done

if git diff --cached --quiet; then
	echo "NOTHING_TO_COMMIT (everything staged was sensitive)"
	exit 3
fi

# Pass 2: name warnings + content sniff on what survived.
sniff='BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}|xoxb-[0-9A-Za-z-]|^[A-Z_]{0,32}(PASSWORD|SECRET|API_KEY)[A-Z_]{0,32}=[^[:space:]]'
git diff --cached --name-only --diff-filter=ACMR | while IFS= read -r f; do
	reason=$(warn_reason "$f") && echo "WARN: $f ($reason)"
	case "${f##*/}" in .env.example|.env.environment.example) continue ;; esac
	if [ -f "$f" ] && grep -qEI "$sniff" -- "$f" 2>/dev/null; then
		echo "WARN: $f (secret-shaped content — review before commit)"
	fi
done

# Staged summary. Full --stat is the most useful form, but on huge changesets it
# alone can be hundreds of lines of model input — above 40 files, collapse to
# per-directory counts (enough to group commits by) plus a one-line total.
staged_file_count=$(git diff --cached --name-only | wc -l)
if [ "$staged_file_count" -le 40 ]; then
	echo "STAGED:"
	git diff --cached --stat
else
	echo "STAGED ($staged_file_count files — grouped by dir, full stat suppressed):"
	git diff --cached --name-only \
		| awk -F/ '{ key = (NF > 1) ? $1 "/" $2 : $1; count[key]++ } END { for (k in count) printf "%5d  %s\n", count[k], k }' \
		| sort -rn
	git diff --cached --shortstat
fi

# Recent subjects so the model matches repo commit style without a `git log` call.
echo "RECENT:"
git log -5 --pretty=format:'  %s' 2>/dev/null
echo
exit 0
