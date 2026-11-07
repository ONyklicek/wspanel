{{-- The corners, and the ones that are not corners.

     Emitted once in the head and keyed on `[data-shape]`, which the layout
     renders from config. No script beside it, unlike density: shape is the
     application's identity rather than a person's working preference, so there
     is nothing for a browser to remember.

     **Why the second block exists.** Zeroing the radius tokens squares 34
     elements on a users page — cards, inputs, buttons — because Tailwind 4
     compiles every `rounded-*` step to `var(--radius-*)`. It squares none of the
     20 pills, because `rounded-full` compiles to `calc(infinity * 1px)` and
     reads no token at all. Badges and tags are content and belong with the rest;
     they are squared by name, through the hooks that exist for exactly this.

     **Avatars stay round, deliberately.** A sharp theme that squares the faces
     reads as broken rather than sharp, and nothing in a token can tell "round
     because it is a card" from "round because it is a face". That is the whole
     reason the framework has both a token layer and a hook layer, and this file
     is where the two meet.
--}}
<style data-wire-shape>
    [data-shape="sharp"] {
        --radius-xs: 0;
        --radius-sm: 0;
        --radius-md: 0;
        --radius-lg: 0;
        --radius-xl: 0;
        --radius-2xl: 0;
        --radius-3xl: 0;
        --radius-4xl: 0;
    }

    /* The pills a token cannot reach.
   
       Two groups, and the line between them is the whole point: **content and
       chrome are squared, faces are not.** A badge is a value with a background
       behind it; the search box and the toggles are furniture. An avatar is a
       picture of a person, and squaring it reads as broken rather than sharp. */
    [data-shape="sharp"] :is(
        /* content */
        [data-wire="badge"],
        [data-wire="table-badge"],
        [data-wire="table-tag"],
        [data-wire="table-tag-overflow"],
        [data-wire="admin-nav-badge-dot"],
        /* the shell's own chrome — without these the content goes square and
           the top bar stays round, which is worse than either on its own */
        [data-wire="global-search-trigger"],
        [data-wire="team-switcher-trigger"],
        [data-wire="notification-bell-count"],
        [data-wire="admin-theme"],
        [data-wire="admin-density"],
        [data-wire="admin-user"]
    ) {
        border-radius: 0;
    }

    /* The buttons inside those groups carry their own `rounded-full`. */
    [data-shape="sharp"] :is([data-wire="admin-theme"], [data-wire="admin-density"]) button {
        border-radius: 0;
    }
</style>
