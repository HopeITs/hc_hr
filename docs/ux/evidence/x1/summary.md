# X1 performance baseline: medians

Raw runs: `raw-runs.jsonl` (128 records, 0 failed). Each cell is the median, with (min–max) when runs differ. Times are milliseconds on the page's own clock from navigation start; server figures come from the router's Server-Timing headers.

## dashboard

| Metric | desktop · cold (n=5) | desktop · first-visit (n=5) | desktop · warm (n=5) | phone · cold (n=5) | phone · first-visit (n=5) | phone · warm (n=5) |
|---|---|---|---|---|---|---|
| Document: server time (ms) | 3,011 <sub>(2,944–3,043)</sub> | 761 <sub>(689–819)</sub> | 736 <sub>(724–801)</sub> | 2,983 <sub>(2,888–3,004)</sub> | 700 <sub>(691–895)</sub> | 685 <sub>(641–739)</sub> |
| Document: time in SQL (ms) | 67 <sub>(64–70)</sub> | 60 <sub>(52–90)</sub> | 57 <sub>(55–63)</sub> | 67 <sub>(66–70)</sub> | 55 <sub>(54–64)</sub> | 54 <sub>(51–58)</sub> |
| Document: SQL queries | 230 <sub>(230–231)</sub> | 227 | 227 | 230 | 227 | 227 |
| Document: HTML size (uncompressed) (KB) | 1,212 | 1,212 | 1,212 | 1,212 | 1,212 | 1,212 |
| Document: transferred (gzip) (KB) | 54.9 <sub>(54.9–54.9)</sub> | 54.9 <sub>(54.9–54.9)</sub> | 54.9 <sub>(54.9–54.9)</sub> | 54.9 <sub>(54.9–54.9)</sub> | 54.9 <sub>(54.9–54.9)</sub> | 54.9 <sub>(54.9–54.9)</sub> |
| Time to first byte (ms) | 3,050 <sub>(2,969–3,085)</sub> | 800 <sub>(715–859)</sub> | 756 <sub>(746–824)</sub> | 3,006 <sub>(2,912–3,036)</sub> | 722 <sub>(716–920)</sub> | 706 <sub>(661–759)</sub> |
| First contentful paint (ms) | 5,056 <sub>(3,676–5,928)</sub> | 1,696 <sub>(1,412–2,472)</sub> | 1,020 <sub>(1,012–1,096)</sub> | 5,464 <sub>(5,344–5,732)</sub> | 3,156 <sub>(3,116–3,332)</sub> | 1,200 <sub>(1,148–1,228)</sub> |
| Flow content rendered (behind overlay) (ms) | 5,139 <sub>(3,753–6,039)</sub> | 1,784 <sub>(1,498–2,543)</sub> | 997 <sub>(988–1,076)</sub> | 5,626 <sub>(5,504–6,031)</sub> | 3,353 <sub>(3,321–3,582)</sub> | 1,484 <sub>(1,381–1,533)</sub> |
| Loading overlay gone (ms) | 6,921 <sub>(4,951–7,925)</sub> | 2,796 <sub>(2,546–4,283)</sub> | 2,247 <sub>(2,043–2,392)</sub> | 11,633 <sub>(10,173–11,984)</sub> | 9,307 <sub>(9,183–9,645)</sub> | 5,777 <sub>(5,563–5,902)</sub> |
| First useful content (ms) | 6,921 <sub>(4,951–7,925)</sub> | 2,796 <sub>(2,546–4,283)</sub> | 2,247 <sub>(2,043–2,392)</sub> | 11,633 <sub>(10,173–11,984)</sub> | 9,307 <sub>(9,183–9,645)</sub> | 5,777 <sub>(5,563–5,902)</sub> |
| Navigation-ready (livewire:initialized) (ms) | 7,714 <sub>(6,642–8,822)</sub> | 4,242 <sub>(4,201–5,247)</sub> | 3,194 <sub>(3,115–3,452)</sub> | 103,285 <sub>(102,977–103,353)</sub> | 100,878 <sub>(100,746–101,188)</sub> | 16,772 <sub>(16,518–16,847)</sub> |
| Settled (load-phase Livewire requests done) (ms) | 7,924 <sub>(6,822–9,065)</sub> | 4,406 <sub>(4,362–5,475)</sub> | 3,333 <sub>(3,294–3,608)</sub> | 104,502 <sub>(104,164–104,604)</sub> | 102,062 <sub>(101,923–102,406)</sub> | 17,840 <sub>(17,584–17,901)</sub> |
| Load event (ms) | 7,891 <sub>(6,722–9,032)</sub> | 4,326 <sub>(4,282–5,449)</sub> | 3,253 <sub>(3,180–3,525)</sub> | 103,645 <sub>(103,308–103,733)</sub> | 101,176 <sub>(101,074–101,546)</sub> | 17,275 <sub>(17,005–17,336)</sub> |
| Largest contentful paint (ms) | 6,048 <sub>(3,780–6,816)</sub> | 1,812 <sub>(1,528–3,928)</sub> | 1,020 <sub>(1,012–1,096)</sub> | 9,252 <sub>(8,320–10,152)</sub> | 7,108 <sub>(7,056–8,016)</sub> | 3,824 <sub>(3,732–4,692)</sub> |
| Requests, whole load | 218 | 218 | 218 | 218 | 218 | 218 |
| Transferred, whole load (KB) | 15,487 <sub>(15,487–15,487)</sub> | 15,487 <sub>(15,487–15,487)</sub> | 494 <sub>(494–494)</sub> | 15,487 <sub>(15,487–15,488)</sub> | 15,487 <sub>(15,487–15,488)</sub> | 494 <sub>(494–494)</sub> |
|   of which third-party CDNs (KB) | 477 <sub>(476–477)</sub> | 476 <sub>(476–476)</sub> | 0.0 | 477 <sub>(476–477)</sub> | 476 <sub>(476–477)</sub> | 0.0 |
| Avatar requests (each a PHP request) | 191 | 191 | 191 | 191 | 191 | 191 |
|   of which refused (403) | 134 | 134 | 134 | 134 | 134 | 134 |
|   avatar bytes transferred (KB) | 14,183 <sub>(14,183–14,183)</sub> | 14,183 <sub>(14,183–14,183)</sub> | 288 | 14,183 <sub>(14,183–14,183)</sub> | 14,183 <sub>(14,183–14,183)</sub> | 288 |
|   avatar server time, summed (ms) | 17,115 <sub>(15,826–19,197)</sub> | 16,448 <sub>(16,081–17,385)</sub> | 16,243 <sub>(15,902–17,664)</sub> | 14,919 <sub>(14,293–15,250)</sub> | 13,979 <sub>(13,151–14,770)</sub> | 13,288 <sub>(12,874–13,719)</sub> |
|   avatar SQL queries, summed | 1,588 <sub>(1,584–1,590)</sub> | 1,586 <sub>(1,583–1,589)</sub> | 1,588 <sub>(1,587–1,590)</sub> | 1,587 <sub>(1,585–1,590)</sub> | 1,587 <sub>(1,584–1,589)</sub> | 1,587 <sub>(1,584–1,587)</sub> |
| Livewire requests during load | 1 | 1 | 1 | 1 | 1 | 1 |
|   their server time, summed (ms) | 94 <sub>(76–133)</sub> | 81 <sub>(71–84)</sub> | 72 <sub>(67–108)</sub> | 90 <sub>(83–92)</sub> | 68 <sub>(65–81)</sub> | 70 <sub>(63–78)</sub> |
|   their SQL queries, summed | 7 | 7 | 7 | 7 | 7 | 7 |
|   their response size (gzip) (KB) | 3.5 <sub>(3.5–3.5)</sub> | 3.5 <sub>(3.5–3.5)</sub> | 3.5 <sub>(3.5–3.5)</sub> | 3.5 <sub>(3.5–3.5)</sub> | 3.5 <sub>(3.5–3.5)</sub> | 3.5 <sub>(3.5–3.5)</sub> |
| Cumulative layout shift | 0.11 <sub>(0.11–0.12)</sub> | 0.11 <sub>(0.11–0.12)</sub> | 0.01 | 0.23 <sub>(0.22–0.24)</sub> | 0.23 <sub>(0.22–0.24)</sub> | 0.00 |
| DOM elements | 5,596 | 5,596 | 5,576 | 5,596 | 5,596 | 5,576 |

## employee_search

| Metric | desktop · cold (n=5) | desktop · first-visit (n=5) | desktop · warm (n=5) | phone · cold (n=5) | phone · first-visit (n=5) | phone · warm (n=5) |
|---|---|---|---|---|---|---|
| Document: server time (ms) | 4,896 <sub>(4,500–4,972)</sub> | 2,390 <sub>(2,255–2,543)</sub> | 2,213 <sub>(1,578–2,278)</sub> | 4,555 <sub>(4,472–4,681)</sub> | 1,937 <sub>(1,871–1,949)</sub> | 1,946 <sub>(1,901–1,970)</sub> |
| Document: time in SQL (ms) | 151 <sub>(148–185)</sub> | 166 <sub>(161–170)</sub> | 152 <sub>(114–162)</sub> | 144 <sub>(141–146)</sub> | 129 <sub>(129–134)</sub> | 133 <sub>(131–137)</sub> |
| Document: SQL queries | 818 | 815 | 815 <sub>(815–816)</sub> | 818 | 815 | 815 |
| Document: HTML size (uncompressed) (KB) | 441 | 441 | 441 | 441 | 441 | 441 |
| Document: transferred (gzip) (KB) | 36.9 <sub>(36.9–36.9)</sub> | 36.9 <sub>(36.9–36.9)</sub> | 36.9 <sub>(36.9–36.9)</sub> | 36.9 <sub>(36.9–36.9)</sub> | 36.9 <sub>(36.9–36.9)</sub> | 36.9 <sub>(36.9–36.9)</sub> |
| Time to first byte (ms) | 4,920 <sub>(4,537–4,989)</sub> | 2,422 <sub>(2,270–2,567)</sub> | 2,237 <sub>(1,589–2,293)</sub> | 4,572 <sub>(4,489–4,698)</sub> | 1,961 <sub>(1,901–1,975)</sub> | 1,960 <sub>(1,928–1,984)</sub> |
| First contentful paint (ms) | 6,932 <sub>(5,364–9,144)</sub> | 3,904 <sub>(3,148–5,156)</sub> | 2,416 <sub>(1,724–2,500)</sub> | 7,056 <sub>(6,960–7,160)</sub> | 4,408 <sub>(4,312–7,620)</sub> | 2,444 <sub>(2,408–2,484)</sub> |
| Flow content rendered (behind overlay) (ms) | 6,970 <sub>(5,413–9,186)</sub> | 3,945 <sub>(3,194–5,192)</sub> | 2,478 <sub>(1,772–2,528)</sub> | 7,397 <sub>(7,222–7,446)</sub> | 4,663 <sub>(4,556–7,811)</sub> | 2,788 <sub>(2,705–2,814)</sub> |
| Loading overlay gone (ms) | 7,588 <sub>(6,186–9,779)</sub> | 4,531 <sub>(3,784–5,822)</sub> | 3,106 <sub>(2,362–3,212)</sub> | 18,868 <sub>(11,296–19,008)</sub> | 16,179 <sub>(8,888–16,386)</sub> | 5,304 <sub>(5,210–5,449)</sub> |
| First useful content (ms) | 7,588 <sub>(6,186–9,779)</sub> | 4,531 <sub>(3,784–5,822)</sub> | 3,106 <sub>(2,362–3,212)</sub> | 18,868 <sub>(11,296–19,008)</sub> | 16,179 <sub>(8,888–16,386)</sub> | 5,304 <sub>(5,210–5,449)</sub> |
| Navigation-ready (livewire:initialized) (ms) | 8,649 <sub>(6,098–14,818)</sub> | 6,048 <sub>(3,778–7,380)</sub> | 2,733 <sub>(1,964–2,826)</sub> | 33,776 <sub>(30,857–33,912)</sub> | 30,837 <sub>(30,270–31,295)</sub> | 4,726 <sub>(4,631–4,826)</sub> |
| Settled (load-phase Livewire requests done) (ms) | 10,808 <sub>(8,417–16,846)</sub> | 8,084 <sub>(5,911–9,423)</sub> | 4,803 <sub>(3,870–5,029)</sub> | 36,184 <sub>(33,316–36,338)</sub> | 33,045 <sub>(32,413–33,414)</sub> | 6,978 <sub>(6,849–7,017)</sub> |
| Load event (ms) | 8,758 <sub>(6,192–14,929)</sub> | 6,172 <sub>(3,877–7,497)</sub> | 2,824 <sub>(2,052–2,924)</sub> | 34,748 <sub>(32,826–34,882)</sub> | 31,814 <sub>(31,238–32,257)</sub> | 5,321 <sub>(5,239–5,394)</sub> |
| Largest contentful paint (ms) | 8,724 <sub>(6,164–14,888)</sub> | 6,132 <sub>(3,848–7,464)</sub> | 2,552 <sub>(2,064–2,944)</sub> | 7,156 <sub>(7,068–8,632)</sub> | 4,532 <sub>(4,392–7,840)</sub> | 2,616 <sub>(2,560–2,688)</sub> |
| Requests, whole load | 38 | 38 | 38 | 38 | 38 | 38 |
| Transferred, whole load (KB) | 4,792 <sub>(4,792–4,792)</sub> | 4,792 <sub>(4,792–4,792)</sub> | 202 <sub>(202–202)</sub> | 4,792 <sub>(4,792–4,792)</sub> | 4,792 <sub>(4,644–4,792)</sub> | 202 <sub>(202–202)</sub> |
|   of which third-party CDNs (KB) | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 |
| Avatar requests (each a PHP request) | 15 | 15 | 15 | 15 | 15 | 15 |
|   of which refused (403) | 0 | 0 | 0 | 0 | 0 | 0 |
|   avatar bytes transferred (KB) | 3,649 <sub>(3,649–3,649)</sub> | 3,649 <sub>(3,649–3,649)</sub> | 0.0 | 3,649 <sub>(3,649–3,649)</sub> | 3,649 <sub>(3,649–3,649)</sub> | 0.0 |
|   avatar server time, summed (ms) | 2,114 <sub>(1,807–2,176)</sub> | 1,317 <sub>(1,204–1,394)</sub> | 1,299 | 1,214 <sub>(1,181–1,267)</sub> | 1,074 <sub>(1,064–1,102)</sub> | 1,036 |
|   avatar SQL queries, summed | 134 <sub>(133–134)</sub> | 133 <sub>(133–135)</sub> | 135 | 133 | 133 <sub>(133–135)</sub> | 133 |
| Livewire requests during load | 1 | 1 | 1 | 1 | 1 | 1 |
|   their server time, summed (ms) | 2,243 <sub>(1,958–2,252)</sub> | 2,029 <sub>(1,952–2,110)</sub> | 2,002 <sub>(1,845–2,206)</sub> | 1,920 <sub>(1,842–1,975)</sub> | 1,699 <sub>(1,656–1,739)</sub> | 1,713 <sub>(1,688–1,797)</sub> |
|   their SQL queries, summed | 708 | 708 | 708 | 708 <sub>(708–709)</sub> | 708 | 708 <sub>(708–709)</sub> |
|   their response size (gzip) (KB) | 16.6 <sub>(16.6–16.6)</sub> | 16.6 <sub>(16.6–16.6)</sub> | 16.6 <sub>(16.6–16.6)</sub> | 16.6 <sub>(16.6–16.6)</sub> | 16.6 <sub>(16.6–16.6)</sub> | 16.6 <sub>(16.6–16.6)</sub> |
| Interaction: action to result (ms) | 1,600 <sub>(1,481–1,655)</sub> | 1,407 <sub>(1,307–1,469)</sub> | 1,413 <sub>(1,156–1,449)</sub> | 1,934 <sub>(1,925–1,986)</sub> | 1,760 <sub>(1,717–1,879)</sub> | 1,781 <sub>(1,708–1,798)</sub> |
|   of which search debounce (ms) | 511 <sub>(509–520)</sub> | 514 <sub>(507–515)</sub> | 508 <sub>(507–518)</sub> | 522 <sub>(517–531)</sub> | 526 <sub>(516–531)</sub> | 524 <sub>(517–529)</sub> |
|   interaction server time (ms) | 987 <sub>(894–1,036)</sub> | 804 <sub>(720–857)</sub> | 808 <sub>(585–842)</sub> | 872 <sub>(852–901)</sub> | 672 <sub>(636–735)</sub> | 657 <sub>(639–671)</sub> |
|   interaction SQL queries | 256 | 256 | 256 | 256 | 256 <sub>(256–257)</sub> | 256 |
|   interaction response (uncompressed) (KB) | 171 | 171 | 171 | 171 | 171 | 171 |
|   interaction response (gzip) (KB) | 12.2 <sub>(12.2–12.2)</sub> | 12.2 <sub>(12.2–12.2)</sub> | 12.2 <sub>(12.2–12.2)</sub> | 12.2 <sub>(12.2–12.2)</sub> | 12.2 <sub>(12.2–12.2)</sub> | 12.2 <sub>(12.2–12.2)</sub> |
| Cumulative layout shift | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.08 <sub>(0.08–0.08)</sub> | 0.08 <sub>(0.08–0.08)</sub> | 0.08 |
| DOM elements | 1,625 | 1,625 | 1,625 | 1,625 | 1,625 | 1,625 |

## contract_create

| Metric | desktop · cold (n=5) | desktop · first-visit (n=5) | desktop · warm (n=5) | phone · cold (n=5) | phone · first-visit (n=5) | phone · warm (n=5) |
|---|---|---|---|---|---|---|
| Document: server time (ms) | 2,346 <sub>(2,219–2,438)</sub> | 419 <sub>(376–449)</sub> | 380 <sub>(327–405)</sub> | 2,215 <sub>(2,162–2,319)</sub> | 356 <sub>(322–411)</sub> | 341 <sub>(319–351)</sub> |
| Document: time in SQL (ms) | 32 <sub>(31–35)</sub> | 28 <sub>(24–30)</sub> | 27 <sub>(25–29)</sub> | 32 <sub>(31–35)</sub> | 25 <sub>(22–28)</sub> | 24 <sub>(22–26)</sub> |
| Document: SQL queries | 123 | 120 | 120 | 123 | 120 | 120 |
| Document: HTML size (uncompressed) (KB) | 208 | 208 | 208 | 208 | 208 | 208 |
| Document: transferred (gzip) (KB) | 35.7 <sub>(35.7–35.7)</sub> | 35.7 <sub>(35.7–35.7)</sub> | 35.7 <sub>(35.7–35.7)</sub> | 35.7 <sub>(35.7–35.7)</sub> | 35.7 <sub>(35.7–35.7)</sub> | 35.7 <sub>(35.7–35.7)</sub> |
| Time to first byte (ms) | 2,378 <sub>(2,238–2,452)</sub> | 432 <sub>(391–461)</sub> | 394 <sub>(339–419)</sub> | 2,238 <sub>(2,190–2,338)</sub> | 370 <sub>(336–424)</sub> | 364 <sub>(330–376)</sub> |
| First contentful paint (ms) | 4,624 <sub>(2,988–5,800)</sub> | 1,056 <sub>(1,004–1,704)</sub> | 592 <sub>(520–612)</sub> | 4,700 <sub>(4,636–4,812)</sub> | 3,016 <sub>(2,996–3,044)</sub> | 1,032 <sub>(1,004–1,076)</sub> |
| Flow content rendered (behind overlay) (ms) | 4,646 <sub>(3,013–5,831)</sub> | 1,078 <sub>(984–1,731)</sub> | 583 <sub>(535–609)</sub> | 4,734 <sub>(4,676–4,858)</sub> | 3,058 <sub>(3,028–3,080)</sub> | 1,117 <sub>(1,088–1,139)</sub> |
| Loading overlay gone (ms) | 5,228 <sub>(3,582–6,466)</sub> | 1,738 <sub>(1,648–2,483)</sub> | 1,158 <sub>(1,098–1,187)</sub> | 7,384 <sub>(7,319–7,494)</sub> | 5,680 <sub>(5,636–5,712)</sub> | 3,046 <sub>(2,955–3,156)</sub> |
| First useful content (ms) | 6,954 <sub>(4,486–7,353)</sub> | 2,607 <sub>(1,992–3,387)</sub> | 1,158 <sub>(1,098–1,187)</sub> | 13,258 <sub>(13,187–14,824)</sub> | 12,358 <sub>(11,393–13,960)</sub> | 4,007 <sub>(3,887–4,112)</sub> |
| Navigation-ready (livewire:initialized) (ms) | 6,568 <sub>(4,130–6,996)</sub> | 2,302 <sub>(1,674–3,076)</sub> | 757 <sub>(708–794)</sub> | 11,652 <sub>(11,538–13,247)</sub> | 10,747 <sub>(9,870–12,385)</sub> | 2,417 <sub>(2,371–2,517)</sub> |
| Settled (load-phase Livewire requests done) (ms) | 6,954 <sub>(4,486–7,353)</sub> | 2,607 <sub>(1,992–3,387)</sub> | 1,158 <sub>(1,098–1,187)</sub> | 13,258 <sub>(13,187–14,824)</sub> | 12,358 <sub>(11,393–13,960)</sub> | 4,007 <sub>(3,887–4,112)</sub> |
| Load event (ms) | 6,673 <sub>(4,215–7,087)</sub> | 2,390 <sub>(1,761–3,157)</sub> | 848 <sub>(802–879)</sub> | 13,062 <sub>(12,548–14,699)</sub> | 11,778 <sub>(11,266–13,410)</sub> | 2,971 <sub>(2,872–3,126)</sub> |
| Largest contentful paint (ms) | 4,660 <sub>(3,024–5,840)</sub> | 1,108 <sub>(1,004–1,744)</sub> | 876 <sub>(840–900)</sub> | 7,320 <sub>(7,240–7,444)</sub> | 5,612 <sub>(3,108–5,640)</sub> | 2,900 <sub>(1,212–3,008)</sub> |
| Requests, whole load | 23 | 23 | 23 | 23 | 23 | 23 |
| Transferred, whole load (KB) | 1,364 <sub>(1,363–1,364)</sub> | 1,364 <sub>(1,364–1,364)</sub> | 190 <sub>(190–190)</sub> | 1,364 <sub>(1,364–1,364)</sub> | 1,364 <sub>(1,364–1,364)</sub> | 190 <sub>(190–190)</sub> |
|   of which third-party CDNs (KB) | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 |
| Avatar requests (each a PHP request) | 1 | 1 | 1 | 1 | 1 | 1 |
|   of which refused (403) | 0 | 0 | 0 | 0 | 0 | 0 |
|   avatar bytes transferred (KB) | 233 | 233 <sub>(233–233)</sub> | 0.0 | 233 | 233 | 0.0 |
|   avatar server time, summed (ms) | 169 <sub>(142–195)</sub> | 85 <sub>(75–140)</sub> | 94 | 142 <sub>(121–161)</sub> | 69 <sub>(67–84)</sub> | 77 |
|   avatar SQL queries, summed | 7 | 7 | 7 | 7 | 7 <sub>(7–8)</sub> | 7 |
| Livewire requests during load | 1 | 1 | 1 | 1 | 1 | 1 |
|   their server time, summed (ms) | 202 <sub>(193–221)</sub> | 156 <sub>(137–168)</sub> | 164 <sub>(147–170)</sub> | 164 <sub>(150–180)</sub> | 140 <sub>(128–149)</sub> | 146 <sub>(125–153)</sub> |
|   their SQL queries, summed | 24 | 24 | 24 | 24 | 24 | 24 |
|   their response size (gzip) (KB) | 6.6 <sub>(6.6–6.7)</sub> | 6.6 <sub>(6.6–6.7)</sub> | 6.7 <sub>(6.6–6.7)</sub> | 6.6 <sub>(6.6–6.7)</sub> | 6.6 <sub>(6.6–6.7)</sub> | 6.6 <sub>(6.6–6.7)</sub> |
| Interaction: action to result (ms) | 192 <sub>(172–210)</sub> | 159 <sub>(146–182)</sub> | 133 <sub>(130–144)</sub> | 869 <sub>(856–939)</sub> | 882 <sub>(863–891)</sub> | 876 <sub>(861–881)</sub> |
|   interaction server time (ms) | 138 <sub>(118–144)</sub> | 102 <sub>(96–131)</sub> | 88 <sub>(84–98)</sub> | 84 <sub>(76–88)</sub> | 85 <sub>(79–93)</sub> | 83 <sub>(77–84)</sub> |
|   interaction SQL queries | 18 | 18 | 18 | 18 | 18 | 18 <sub>(18–19)</sub> |
|   interaction response (uncompressed) (KB) | 1.8 | 1.8 | 1.8 | 1.8 | 1.8 | 1.8 |
|   interaction response (gzip) (KB) | 3.0 <sub>(3.0–3.0)</sub> | 3.0 <sub>(3.0–3.0)</sub> | 3.0 <sub>(3.0–3.0)</sub> | 3.0 <sub>(3.0–3.0)</sub> | 3.0 <sub>(3.0–3.0)</sub> | 3.0 <sub>(3.0–3.0)</sub> |
| Cumulative layout shift | 0.14 <sub>(0.14–0.14)</sub> | 0.14 <sub>(0.14–0.14)</sub> | 0.14 <sub>(0.14–0.14)</sub> | 0.18 <sub>(0.18–0.19)</sub> | 0.18 <sub>(0.18–0.19)</sub> | 0.01 |
| DOM elements | 1,231 | 1,231 | 1,231 | 1,231 | 1,231 | 1,231 |

## leave_form

| Metric | desktop · cold (n=5) | desktop · first-visit (n=5) | desktop · warm (n=5) | phone · cold (n=5) | phone · first-visit (n=5) | phone · warm (n=5) |
|---|---|---|---|---|---|---|
| Document: server time (ms) | 2,380 <sub>(2,276–2,513)</sub> | 327 <sub>(286–348)</sub> | 316 <sub>(297–350)</sub> | 2,286 <sub>(2,266–2,326)</sub> | 291 <sub>(280–337)</sub> | 277 <sub>(263–319)</sub> |
| Document: time in SQL (ms) | 37 <sub>(35–40)</sub> | 34 <sub>(28–35)</sub> | 32 <sub>(27–36)</sub> | 37 <sub>(35–38)</sub> | 30 <sub>(30–31)</sub> | 29 <sub>(28–31)</sub> |
| Document: SQL queries | 149 | 146 | 146 | 149 | 146 | 146 |
| Document: HTML size (uncompressed) (KB) | 185 | 185 | 185 | 185 | 185 | 185 |
| Document: transferred (gzip) (KB) | 28.4 <sub>(28.4–28.4)</sub> | 28.4 <sub>(28.4–28.4)</sub> | 28.4 <sub>(28.4–28.5)</sub> | 28.4 <sub>(28.4–28.5)</sub> | 28.4 <sub>(28.4–28.5)</sub> | 28.4 <sub>(28.4–28.4)</sub> |
| Time to first byte (ms) | 2,410 <sub>(2,311–2,526)</sub> | 339 <sub>(299–374)</sub> | 327 <sub>(307–359)</sub> | 2,302 <sub>(2,294–2,355)</sub> | 302 <sub>(291–361)</sub> | 304 <sub>(281–329)</sub> |
| First contentful paint (ms) | 3,888 <sub>(3,232–7,028)</sub> | 936 <sub>(892–7,712)</sub> | 536 <sub>(524–544)</sub> | 4,796 <sub>(4,744–7,184)</sub> | 3,012 <sub>(3,000–3,088)</sub> | 1,104 <sub>(1,036–1,156)</sub> |
| Flow content rendered (behind overlay) (ms) | 3,872 <sub>(3,270–7,070)</sub> | 982 <sub>(939–7,749)</sub> | 523 <sub>(508–566)</sub> | 4,940 <sub>(4,874–7,390)</sub> | 3,179 <sub>(3,135–3,256)</sub> | 1,138 <sub>(1,092–1,209)</sub> |
| Loading overlay gone (ms) | 4,451 <sub>(3,841–7,644)</sub> | 1,593 <sub>(1,505–8,315)</sub> | 1,070 <sub>(1,045–1,142)</sub> | 7,729 <sub>(7,725–8,175)</sub> | 5,970 <sub>(5,923–6,044)</sub> | 2,935 <sub>(2,843–3,046)</sub> |
| First useful content (ms) | 4,451 <sub>(3,841–7,644)</sub> | 1,593 <sub>(1,505–8,315)</sub> | 1,070 <sub>(1,045–1,142)</sub> | 7,729 <sub>(7,725–8,175)</sub> | 5,970 <sub>(5,923–6,044)</sub> | 2,935 <sub>(2,843–3,046)</sub> |
| Navigation-ready (livewire:initialized) (ms) | 6,549 <sub>(5,810–10,190)</sub> | 1,628 <sub>(1,535–9,734)</sub> | 670 <sub>(664–743)</sub> | 11,956 <sub>(11,716–12,732)</sub> | 10,856 <sub>(10,162–13,928)</sub> | 2,466 <sub>(2,394–2,593)</sub> |
| Settled (load-phase Livewire requests done) (ms) | 6,778 <sub>(6,062–10,428)</sub> | 1,810 <sub>(1,706–9,910)</sub> | 1,070 <sub>(1,045–1,142)</sub> | 12,795 <sub>(12,571–13,578)</sub> | 11,713 <sub>(11,027–14,765)</sub> | 3,362 <sub>(3,278–3,506)</sub> |
| Load event (ms) | 6,606 <sub>(5,870–10,249)</sub> | 1,682 <sub>(1,589–9,792)</sub> | 724 <sub>(715–794)</sub> | 12,264 <sub>(12,048–13,028)</sub> | 11,175 <sub>(10,467–14,227)</sub> | 2,882 <sub>(2,783–3,002)</sub> |
| Largest contentful paint (ms) | 3,888 <sub>(3,288–7,084)</sub> | 1,000 <sub>(960–7,764)</sub> | 740 <sub>(724–808)</sub> | 7,520 <sub>(4,968–7,776)</sub> | 5,848 <sub>(5,788–5,964)</sub> | 2,788 <sub>(1,156–2,992)</sub> |
| Requests, whole load | 22 | 22 | 22 | 22 | 22 | 22 |
| Transferred, whole load (KB) | 1,382 <sub>(1,382–1,382)</sub> | 1,382 <sub>(1,382–1,382)</sub> | 185 <sub>(185–185)</sub> | 1,382 <sub>(1,382–1,382)</sub> | 1,382 <sub>(1,382–1,382)</sub> | 185 <sub>(185–185)</sub> |
|   of which third-party CDNs (KB) | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 | 428 <sub>(428–428)</sub> | 428 <sub>(428–428)</sub> | 0.0 |
| Avatar requests (each a PHP request) | 1 | 1 | 1 | 1 | 1 | 1 |
|   of which refused (403) | 0 | 0 | 0 | 0 | 0 | 0 |
|   avatar bytes transferred (KB) | 255 | 255 <sub>(255–255)</sub> | 0.0 | 255 | 255 | 0.0 |
|   avatar server time, summed (ms) | 157 <sub>(132–171)</sub> | 72 <sub>(67–140)</sub> | 85 | 144 <sub>(133–157)</sub> | 73 <sub>(61–79)</sub> | 68 |
|   avatar SQL queries, summed | 7 | 7 <sub>(7–8)</sub> | 7 | 7 | 7 | 7 |
| Livewire requests during load | 1 | 1 | 1 | 1 | 1 | 1 |
|   their server time, summed (ms) | 196 <sub>(186–215)</sub> | 132 <sub>(119–142)</sub> | 128 <sub>(119–138)</sub> | 192 <sub>(185–200)</sub> | 125 <sub>(115–142)</sub> | 125 <sub>(122–136)</sub> |
|   their SQL queries, summed | 18 <sub>(18–19)</sub> | 18 <sub>(18–19)</sub> | 18 | 18 | 18 | 18 |
|   their response size (gzip) (KB) | 8.7 <sub>(8.7–8.7)</sub> | 8.7 <sub>(8.7–8.7)</sub> | 8.7 <sub>(8.7–8.7)</sub> | 8.7 <sub>(8.7–8.7)</sub> | 8.7 <sub>(8.7–8.7)</sub> | 8.7 <sub>(8.7–8.7)</sub> |
| Interaction: action to result (ms) | 193 <sub>(190–211)</sub> | 192 <sub>(175–194)</sub> | 175 <sub>(157–182)</sub> | 855 <sub>(854–917)</sub> | 854 <sub>(823–896)</sub> | 829 <sub>(813–860)</sub> |
|   interaction server time (ms) | 144 <sub>(128–164)</sub> | 139 <sub>(127–144)</sub> | 128 <sub>(110–138)</sub> | 143 <sub>(129–144)</sub> | 126 <sub>(106–132)</sub> | 120 <sub>(114–127)</sub> |
|   interaction SQL queries | 37 <sub>(37–38)</sub> | 37 | 37 | 37 | 37 | 37 |
|   interaction response (uncompressed) (KB) | 37.9 | 37.9 | 37.9 | 37.9 | 37.9 | 37.9 |
|   interaction response (gzip) (KB) | 7.9 <sub>(7.9–7.9)</sub> | 7.9 <sub>(7.9–7.9)</sub> | 7.9 <sub>(7.9–7.9)</sub> | 7.9 <sub>(7.9–7.9)</sub> | 7.9 <sub>(7.9–7.9)</sub> | 7.9 <sub>(7.9–7.9)</sub> |
| Cumulative layout shift | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.00 <sub>(0.00–0.00)</sub> | 0.00 |
| DOM elements | 869 | 869 | 869 | 869 | 869 | 869 |
