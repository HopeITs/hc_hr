# Manual checks still open: A6 (PDF) and A5-M (screen reader)

Both need a person on a real host. Use **synthetic data only**, never real HR records. Record each result as pass/fail with the date, the tester and a screenshot or recording, and add it to `a6-implementation.md` / `a5m-implementation.md`.

## 1. A6: real contract PDF (AC-A6-01), about 30 min, staging or production host

Needs: a user with `contracts.export` and salary access (an HR role in the contract's city), and synthetic annual and service contracts.

1. On the host run `php artisan contracts:export-doctor` and keep the output. Everything must report OK (Chrome or browserless, node, permissions).
2. Open each contract and press **Export PDF**: annual in English, annual in Arabic, service in English, service in Arabic (switch the site language each time).
3. For each PDF check:
   - [ ] The file is not empty and opens.
   - [ ] Arabic is joined and right-to-left (no separated letters, no reversed words); English is left-to-right.
   - [ ] Names, dates, positions and terms match the contract screen.
   - [ ] The pay figure and tiers match the contract screen.
   - [ ] Nothing is cut at page edges and page breaks do not split a clause badly.
4. Failure and retry: stop the renderer or set a wrong `BROWSERSHOT_WS_ENDPOINT`, press Export. Expect a translated error with **Try again** and no new contract. Restore the setting and press Try again: the PDF downloads and the contract count is unchanged.
5. Refusals: as super-admin and as general-manager there is no export button, and opening `/contracts/{id}/export` directly gives 403.
6. If all Arabic PDFs are clean, record it: this is the proof the brief requires before the dead `forPdf`/`ArabicText` code may be removed.

## 2. A5-M: screen reader (AC-A5-M-07), about 30 min

Needs: NVDA with Firefox or Chrome (Windows), or VoiceOver with Safari. Use My Leaves, then one settings dialog (for example Departments). Run it once in English and once in Arabic.

1. Open **Request leave**. [ ] The dialog is announced with its title and "dialog". [ ] Focus lands in the first field.
2. Type a reason, press **Escape**. [ ] The question "Discard your changes?" is announced. [ ] Focus is on **Keep editing**.
3. Press Escape again. [ ] You are back in the form and the reason is still there.
4. Press **Cancel**, then **Discard changes**. [ ] The dialog closes. [ ] Focus returns to the **Request leave** button. [ ] Reopening shows an empty form.
5. Open a request with nothing typed and press **Cancel**. [ ] It closes at once, with no question.
6. Click the dark area outside a dialog with text typed. [ ] Nothing happens.
7. Tab through the dialog. [ ] Focus never leaves it, and the page behind is not readable.
8. Phone size with the keyboard open: [ ] Cancel and Save are reachable.

## 3. Hand-off

Both sheets, filled in, go to the QA lead and the technical lead for G-A.
