# AtoA Resolver (resolver.une.education)

>**Deterministic Compute & Logic Layer for Agentic Trade Compliance**  
>Eliminating LLM hallucinations in customs, sanctions (OFAC/EAR), and export controls using formal constraint satisfaction.

---

## 1. Problem Statement
Large Language Models (LLMs) are inherently probabilistic and suffer from hallucinations when evaluating nested, multi-jurisdiction trade regulations.

**AtoA Resolver** replaces heuristic guessing with a formal, deterministic constraint satisfaction graph (DAG/CSP).


## 2. Supported Interfaces

### A. Model Context Protocol (MCP)
Endpoint : `https://resolver.une.education/mcp.php`

### B. Synchronous REST API
Endpoint : `https://resolver.une.education/v1/resolve`

## 3. Architecture
- EFBS : Constraint Graph (`ConstraintGraph.php`) with transitivities and mutual exclusion arbitration.
- Nomenclature : Direct local SQLite cross-linking with TARIC nomenclature.
- Sanctions : Trigram Full-Text Search (FTS5) over Title 15/31 CFR.

---
Part of the *une.education* AtoA Ecosystem.
