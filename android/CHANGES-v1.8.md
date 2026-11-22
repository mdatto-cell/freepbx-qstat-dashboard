# v1.8 — Compact iOS cards and Android status bar fix

- Compact typography and padding in call cards while retaining client name and number as separate lines.
- Align call count at the right edge of the journal heading.
- Restore system-managed status/navigation insets, avoiding the header overlapping the status bar on Android 15.
- Keep existing API, authentication, playback and pull-to-refresh logic.
- targetSdk 34 as a compatibility measure for Android 15 edge-to-edge enforcement.

Build and runtime have not been verified in this environment.
