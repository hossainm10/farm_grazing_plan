# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
## [Unreleased]

### Added

- Add actual start date, graze height, residual height, geometry, photo, and
  notes fields to the "Complete grazing event" quick form. The date, notes,
  and geometry are saved on the event's movement log. Heights and photos are
  saved on observation logs for the paddocks the animals entered and left.

### Changed

- The module now depends on `farm_observation`, `farm_quantity_standard`, and `farm_unit`.

## [Unreleased]

## [1.0.0-alpha6] 2026-10-12

### Added

- Add a "Complete grazing event" quick form that shows a dropdown of the next
  pending grazing event for each asset in each grazing plan the user has
  access to, and marks the selected event as done on submit.
- Add a "Complete" button to the first pending grazing event in the grazing
  events form, which redirects to the "Complete grazing event" quick form and
  prepopulates the dropdown.
- Add an "Abandon" button to all pending grazing events in the grazing events
  form, which redirects to a confirmation form. A reason for abandoning can be
  optionally provided.
- Add `getAsset()`, `getLocation()`, and `getStatus()` methods to the grazing
  event bundle class.

### Changed

- The "Add grazing event" form now collects the duration and recovery in
  days. The values are converted to hours before being stored.

### Fixed

- Fix an error when saving the grazing events form for a plan that includes
  an asset with no pending grazing events.

## [1.0.0-alpha5] 2026-10-09

### Added

- Provide a form for editing all grazing events together.
  - Add multiple pending grazing events for each asset.
  - Show completed and pending events in separate tables.
  - Only pending events can be edited.
  - Completed events show the actual start date.
  - Pending events show the pending start date.
  - Log timestamps are updated automatically to match pending event start dates.
  - Pending events can be rearranged via drag-and-drop.
  - Unsaved pending events can be removed from the form before saving.
  - The next planned movement date can be set per asset. Pending event start
    dates are computed from it, and are recomputed automatically when the
    order or planned durations change.

## [1.0.0-alpha4] 2026-10-02

### Added

- Allow the "Add grazing event" form to create new movement logs.

### Changed

- Move add event form's movement log validation logic into an entity constraint
  so that it is checked in more places (eg: API requests).
- Hide assets that the user doesn't have access to from the timeline.
- Prevent new grazing events and movement logs from being added before the
  last existing grazing event for the same asset in the plan, ensuring that
  events are always added to the end of the plan in chronological order.

### Removed

- Do not link to plan from GrazingEventLog validation message.

## [1.0.0-alpha3] 2026-09-18

### Added

- Add additional help text to "Add grazing event" form.

### Changed

- Sort grazing events chronologically.
- Do not allow the same movement log to be used in multiple grazing plans.
- Movement logs must reference one asset and location.
- Restrict editing of grazing event logs outside the plan context.

### Fixed

- Only movement logs can be added to a grazing plan.

## [1.0.0-alpha2] 2026-02-14

### Added

- Added support for farmOS v4.

### Changed

- Code cleanup and modernization.

### Removed

- Dropped support for farmOS v3.
- Drop inherited `asset` and `log` fields from grazing plan entity.

## [1.0.0-alpha1] 2024-09-24

This is the first official alpha release of the farmOS Grazing Plan module.

It is considered "alpha" because it is still very much a proof-of-concept, and
is not going to be useful for most real-world grazing planning. It can be used
to visualize grazing events on a timeline, but using it for day-to-day
management is quite tedious. Moving forward, we hope to work as a community to
identify and prioritize next steps based on what would be most useful.

This project is inspired by the original
[farmOS v1 Grazing module](https://github.com/farmos/farm_grazing), but was
built from scratch with a new data architecture based on farmOS v3. The v1
module was designed around a specific prescriptive grazing management system,
but this one is designed to be more general. The hope is that it can be used as
a foundation to build more prescriptive workflows on top of.

Here is a summary of the major features this release provides:

### Added

- "Grazing" plan type, with a "Season" reference field
- "Grazing event" plan record type, with a "Log" reference field, a "Planned
  start date/time" field, and fields for "Duration (hours)" and "Recovery
  (hours)".
- Form for adding movement logs to the plan, along with plan-specific metadata.
- Gantt chart visualization of grazing events in the plan, using
  [svelte-gantt](https://github.com/ANovokmet/svelte-gantt), with the ability to
  view by asset or by location.

[Unreleased]: https://github.com/mstenta/farm_grazing_plan/compare/1.0.0-alpha6...HEAD
[1.0.0-alpha6]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha6
[1.0.0-alpha5]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha5
[1.0.0-alpha4]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha4
[1.0.0-alpha3]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha3
[1.0.0-alpha2]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha2
[1.0.0-alpha1]: https://github.com/mstenta/farm_grazing_plan/releases/tag/1.0.0-alpha1
