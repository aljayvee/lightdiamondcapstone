# BDO Cash Transaction Slip — print layout reference

WBPMS does **not** reproduce BDO's slip. HR gets blank BDO Network Bank Cash
Transaction Slips from the bank (Light Diamond is a BDO client) and the
Transaction Slips page prints only the details onto them, like a stamp
machine (`resources/views/hr/reports/transaction-slips.php`, `?print=1`).

## Source of the measurements

- A blank slip scanned at **400 dpi** on a Letter page (3400 × 4400 px,
  1 px = 0.0635 mm), slip placed in the scanner's top-left corner.
  File: `BDO-Transaction-Slip.pdf` (owner's copy; kept out of git on purpose —
  it is the bank's form).
- Measured 2026-11-22 by detecting the printed blue lines, checkbox outlines
  and panel edges in the scan, then checked by drawing the printed details on
  the scan.
- The earlier positions assumed a 210 × 101.5 mm slip. The real slip is
  wider and the lines higher: the old values were about 1.3 % too narrow
  (≈ 2 mm off at the Amount column) and ≈ 0.85 mm too low.

All values are millimetres from the slip's top-left corner.

## Slip

| Item | Value |
|---|---|
| Slip size | 212.8 × 100.0 mm |
| Right (light blue) panel | x 124.8 → 212.8 |

## Printed lines on the slip (where the details must sit above)

| Line | y | x from → to |
|---|---|---|
| Account Name line | 21.78 | 34.8 → 121.4 |
| Account No. line | 29.46 | 34.8 → 121.7 |
| Payor / Reference line (full width) | 37.62 | 3.5 → 121.5 |
| Company / Institution line | 45.31 | 34.8 → 121.6 |
| Subscriber line (full width) | 52.93 | 3.2 → 121.5 |
| Borrower line (full width) | 60.55 | 3.2 → 121.5 |
| Date line (right panel) | 12.86 | 128.0 → 208.3 |
| Denomination table rows | 21.02, 29.34, 37.59, 45.88, 54.10, 62.42, 70.68, 78.93, 87.19 | 128.0 → 208.3 |
| Pieces column (vertical) | x 160.37 | y 21.0 → 79.1 |
| Amount column (vertical) | x 175.90 | y 21.0 → 87.2 |
| Institution / Product code ticks | x 78.7, 83.4, 88.3, 93.1, 97.9 / 107.2, 111.9, 116.7, 121.5 | y ≈ 44–45.3 |

Checkboxes (left, top; ≈ 2.35 mm square): Deposits 3.24, 14.03 · Current
6.41, 17.27 · Savings 6.41, 20.57 · Time Deposit 6.41, 23.81 · Deposit
Reference 9.72, 29.53 · Bills payment 3.24, 38.42 · Payment 3.17, 53.66 ·
Loan 6.35, 56.90.

## What WBPMS prints (details layer `.d`)

| Detail | left | top | width × height | Rule |
|---|---|---|---|---|
| ✓ Deposits | 3.24 | 14.03 | 2.4 × 2.4 | inside the box |
| ✓ Current / ✓ Savings | 6.41 | 17.27 / 20.57 | 2.4 × 2.4 | inside the box (one only, from the account type) |
| Account name | 35.5 | 17.18 | 85.5 × 4.4 | 4.6 above its line |
| Account number | 35.5 | 24.86 | 85.5 × 4.4 | 4.6 above its line |
| Payor (employee) | 35.5 | 33.32 | 42.5 × 4.4 | 4.3 above its line |
| Reference (PR-yyyymmdd-NNNN) | 79.2 | 33.32 | 42 × 4.4 | 4.3 above its line |
| Company (Light Diamond Enterprises) | 35.5 | 40.71 | 42.5 × 4.4 | 4.6 above its line |
| Date | 132.7 | 8.16 | 70 × 4.4 | 4.7 above its line |
| Description "Payroll" | 129.2 | 21.02 | 30 × 8.3 | first table row |
| Pieces "1" | 160.37 | 21.02 | 15.5 × 8.3 | between the column lines |
| Amount | 176.6 | 21.02 | 31 × 8.3 | right-aligned in the Amount column |
| Total amount | 176.6 | 78.93 | 31 × 8.26 | Total Amount row |

Fonts: Arial Narrow bold, 9 pt (8 pt reference, 11 pt total).

## Alignment crosses (test print only)

Four 4 mm red crosses whose centres sit on line corners of the slip:
(34.8, 21.78) start of the Account Name line · (121.5, 60.55) end of the
Borrower line · (160.37, 21.02) top of the Pieces column · (175.9, 87.19)
bottom of the Amount column. HR prints one slip on plain paper, holds it over
a blank slip against the light and moves Left/Right / Up/Down (0.5 mm steps,
saved per browser, max ±15 mm) until every cross centre is on its corner.

## Printing

- Page: A5 landscape, no margins (the slip is 212.8 mm wide; every printed
  detail ends by x ≈ 207.6 mm, so nothing falls outside a 210 mm page).
- Feed the slip against the printer's left guide.
- To recalibrate for a printer, use the page's alignment controls. Never edit
  individual field positions for one printer; change this table only if BDO
  changes the slip itself (re-scan at 400 dpi and re-measure).
