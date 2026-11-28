# Bundled usage classification lists

Retrieved on 2026-10-20. These files are the offline starting point; validated
updates are stored centrally in `usage_list_versions`, not written over these files.

| File                | Source                                                                                                          | SHA-256                                                            |
| ------------------- | --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| counter-robots.json | [COUNTER-Robots](https://github.com/atmire/COUNTER-Robots/blob/master/COUNTER_Robots_list.json)                 | `0f27b631cb19c6effaffe1bcfa7131c04128ed99b627cd6f0b64e7111fbe80ae` |
| robots.txt          | [Make Data Count robots](https://github.com/CDLUC3/Make-Data-Count/blob/master/user-agents/lists/robot.txt)     | `ee297565bb324b6c0e4dd6b0197a5280b4d5b0f291f53d5663775e53342d28ab` |
| machines.txt        | [Make Data Count machines](https://github.com/CDLUC3/Make-Data-Count/blob/master/user-agents/lists/machine.txt) | `2bf7f7e44c1c810e0f30b0d124199c89bf190c98665265e917051eccc06e079f` |

The COUNTER-Robots MIT license is included in `LICENSE-COUNTER-Robots`.
The split robot/machine lists are attributed to California Digital Library's
Make Data Count project.

Adapter version 1 removes generic machine patterns from the combined crawler
rules and adds generic HTTP clients (including modern Java version strings).
Specific crawler rules still take precedence over machine patterns. Matching
is case-insensitive. Unknown agents are Regular; an absent agent is classified
using the MDC `no_user_agent` pattern.

Changes to sources or adapter rules must pass the offline classification tests.
Each installed version records source URLs, checksums, adapter version and time.
