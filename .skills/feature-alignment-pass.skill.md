---
name: feature-alignment-pass
description: Reconcile and normalize docs/features spec, state, and decisions files to ensure deterministic alignment without semantic invention
---

# Purpose

Use this skill when:
- feature specs have just been implemented
- context files may be out of sync
- running a consistency/cleanup pass across docs/features/*
- preparing for verification or release

This skill helps produce:
- aligned spec/state/decision files
- normalized and deterministic feature context
- minimal, safe diffs

Do not use this skill for:
- inventing new features or behavior
- resolving ambiguous intent
- rewriting large portions of documentation
- modifying execution specs

# Inputs

Expect inputs such as:
- docs/features/*.spec.md
- docs/features/*.md
- docs/features/*.decisions.md

If any critical input is missing:
- do not invent it
- proceed with available files only

# Reasoning Principles

Apply these principles strictly:

## 1. Spec Discipline
- Spec files define current intent only
- No history allowed
- Must be canonical and internally consistent

## 2. State Alignment
- State reflects implemented reality
- Must align with spec
- No speculation or history

## 3. Decision Ledger Integrity
- Append-only
- Never modify or delete entries
- Only append if absolutely necessary

## 4. No Semantic Invention
- Do not guess missing behavior
- Do not resolve ambiguity
- Prefer leaving unchanged over being wrong

## 5. Minimal Deterministic Changes
- Only change what is required
- Normalize structure and formatting
- Remove duplication
- Preserve meaning

## 6. Deterministic Output
- Stable ordering
- No randomness
- No timestamps
- Same input → same output

# Execution Steps

For each feature:

1. Compare:
    - spec vs state vs decisions

2. Detect:
    - drift (misalignment)
    - duplication
    - structural issues
    - formatting inconsistencies

3. Apply ONLY safe fixes:
    - normalization (sections, formatting)
    - wording alignment where intent is clear
    - duplicate removal
    - canonical ordering

4. DO NOT:
    - invent missing content
    - resolve unclear intent
    - modify decision history

5. If unresolved issues remain:
    - leave files unchanged
    - report clearly

# Output

Return:

- modified files (if safe changes exist)

AND a JSON summary:

{
"status": "ok|partial|blocked",
"features_checked": [],
"files_modified": [],
"issues_found": [],
"issues_unresolved": [],
"requires_manual_review": true|false
}

# Completion Criteria

- spec/state are aligned
- decisions remain intact
- formatting is normalized
- no semantic drift introduced
- minimal diff footprint