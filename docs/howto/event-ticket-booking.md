---
title: "How-To: Event Ticket Booking"
category: product
tags: [booking, tickets, capacity, concurrency, inventory]
difficulty: intermediate
related: [resource-booking, prevent-double-booking, resource-reservation]
---

# How-To: Event Ticket Booking

Demonstrates event capacity management with per-user ticket purchasing.
Field trial: FT196 (`../NENE2-FT/ticketlog/`). Includes ATK-01~12 cracker attack test.

## Pattern summary

| Concern | Approach |
|---|---|
| Capacity tracking | `remaining = capacity - COUNT(tickets)` computed on read |
| Sold-out | 409 Conflict when `remaining <= 0` |
| Duplicate purchase | `UNIQUE(event_id, user_id)` → catches concurrent double-buy |
| IDOR cancel | `user_id` ownership check → 403 if mismatch |
| Admin key | `hash_equals()` fail-closed |

## ATK-01~12 results: ALL PASS
