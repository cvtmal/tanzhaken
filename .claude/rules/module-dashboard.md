---
paths:
  - "src/Dashboard/**"
  - "tests/**/Dashboard/**"
  - "templates/dashboard/**"
  - "translations/dashboard+intl-icu.en.yaml"
---

# Module `Dashboard`

- **Holds:** the dashboard page.
- **May depend on:** every other module.
- **Owning cycles:** #17.
- **Routes (S5):** `dashboard` `/dashboard` (#17).
- **Tables (S3):** none; reads through the other modules' read models.
- **Templates:** `templates/dashboard/**`.
- **Translation domain:** `dashboard` (`translations/dashboard+intl-icu.en.yaml`).
- **Hotspot slots:** none.

Namespace `App\Dashboard\<Layer>`. Placeholder actions in `src/Dashboard/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #17 notes (dashboard)

- **Read model:** `App\Dashboard\ReadModel\TodaysLessons::forTeacher(Teacher): TodayBoard` — one DBAL query for today's and the next 7 days' lessons of non-archived courses (+1 `COUNT` only when no lesson row, for `hasCourses`); `TodayBoard` (`today`, `upcoming` ≤ `UPCOMING_LIMIT` 10, `now`, `hasCourses`, `hero()`, `others()`, `canOpenCheckIn()`, `todayCourseIds()`) and `DashboardLesson` (`final readonly`). Renewal rows come from `App\Credits\ReadModel\Balances::renewalNeeded()` only. The page makes 3 queries (user refresh, lessons, renewals) at any size; `tests/Functional/Dashboard/DashboardPageTest.php` proves it stays constant.
- **Today** = `starts_at_utc` in [start of today, start of tomorrow) in the **teacher's** zone; lesson times print in the course's zone (zone id appended when it differs).
- **Check-in eligibility (P3):** a today lesson, not cancelled, `starts_at − 15 min ≤ now` (`DashboardLesson::CHECKIN_LEAD_MINUTES`); the link goes to `checkin_manage` (GET; #16 opens the window).
- **Hero (P4):** the latest eligible today lesson, else the next one to start; never a cancelled one. Only the hero carries the filled primary button; other eligible lessons get a secondary "Open check-in".
- **No POST on the dashboard.** The "+10" quick grant of B1/B2 and Low (1–2) rows belong to #12 (P6/P7, D-044).
- Templates: `templates/dashboard/index.html.twig` + partials `_hero`, `_today_lesson`, `_lesson_badge`, `_upcoming`, `_renewals`; styles `assets/styles/dashboard/dashboard.css`.
