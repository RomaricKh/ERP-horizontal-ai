# Requirements Document

## Introduction

The ERP application sidebar currently renders 13 menu items as a flat list, causing overflow on smaller viewports and making navigation cumbersome. This feature introduces collapsible grouped sections (accordion-style) to the sidebar menu, leveraging the existing SB Admin 2 collapse patterns. Menu items will be organized into logical groups (Overview, Core Data, Operations, Finance) with collapsible headers, while preserving permission-based visibility and active-route highlighting.

## Glossary

- **Sidebar**: The left-hand vertical navigation panel rendered by `appShell.js` inside the `#accordionSidebar` container.
- **Menu_Item**: A single navigable entry in the sidebar, consisting of an id, label, icon, hash route, and permission string.
- **Menu_Group**: A named, collapsible section in the sidebar that contains one or more Menu_Items under a shared heading.
- **MENU_CONFIG**: The JavaScript configuration array in `appShell.config.js` that defines all sidebar navigation entries.
- **Sidebar_Renderer**: The `renderMenu()` function in `appShell.js` responsible for building sidebar DOM elements from MENU_CONFIG.
- **Active_Route**: The current hash-based route displayed in the browser, used to determine which Menu_Item is highlighted.
- **Group_Header**: The clickable heading element for a Menu_Group that toggles the collapse state of its child items.
- **Collapse_Panel**: The collapsible container within a Menu_Group that holds the group's Menu_Items and can be expanded or collapsed.
- **Permission_Gate**: The `hasPermission()` check that determines whether a Menu_Item or Menu_Group is visible to the current user.

## Requirements

### Requirement 1: Nested Group Structure in MENU_CONFIG

**User Story:** As a developer, I want MENU_CONFIG to support a nested group structure, so that menu items can be logically organized into collapsible sections without modifying each item individually.

#### Acceptance Criteria

1. THE MENU_CONFIG SHALL support entries of type "group" containing a group label, a group icon, and an ordered array of child Menu_Items.
2. THE MENU_CONFIG SHALL support entries of type "item" at the top level for Menu_Items that do not belong to any group.
3. WHEN a group entry is defined in MENU_CONFIG, THE Sidebar_Renderer SHALL treat the child items array as the contents of that group's Collapse_Panel.
4. THE MENU_CONFIG SHALL preserve all existing Menu_Item properties (id, label, icon, hash, permission) for items within groups.

### Requirement 2: Collapsible Group Rendering

**User Story:** As a user, I want sidebar menu groups to be rendered as collapsible accordion sections, so that I can expand only the groups I need and keep the sidebar compact.

#### Acceptance Criteria

1. WHEN the Sidebar_Renderer encounters a group entry, THE Sidebar_Renderer SHALL render a Group_Header element followed by a Collapse_Panel containing the group's child Menu_Items.
2. THE Group_Header SHALL display the group label and group icon.
3. THE Collapse_Panel SHALL use the SB Admin 2 Bootstrap collapse component (CSS classes `collapse`, `collapse-inner`, `collapse-item`) for expand/collapse behavior.
4. WHEN a user clicks a Group_Header, THE Collapse_Panel SHALL toggle between expanded and collapsed states.
5. THE Sidebar_Renderer SHALL render a visual chevron indicator on the Group_Header that reflects the current collapse state (down-arrow when expanded, right-arrow when collapsed).

### Requirement 3: Ungrouped Top-Level Items

**User Story:** As a user, I want menu items that don't belong to a group (like Dashboard) to appear as standalone links in the sidebar, so that frequently used items remain immediately accessible.

#### Acceptance Criteria

1. WHEN the Sidebar_Renderer encounters a top-level item entry in MENU_CONFIG, THE Sidebar_Renderer SHALL render the item as a standard `nav-item` link without any group wrapper.
2. THE Sidebar_Renderer SHALL render top-level items in the order they appear in MENU_CONFIG, interspersed with group entries as configured.

### Requirement 4: Permission Gating for Groups and Items

**User Story:** As an administrator, I want permission checks to apply to individual menu items within groups, so that users only see the items they are authorized to access.

#### Acceptance Criteria

1. WHEN rendering a group's child items, THE Sidebar_Renderer SHALL apply the Permission_Gate to each child Menu_Item individually.
2. IF all child Menu_Items within a group are hidden by the Permission_Gate, THEN THE Sidebar_Renderer SHALL hide the entire Menu_Group including its Group_Header.
3. WHEN rendering a top-level item, THE Sidebar_Renderer SHALL apply the Permission_Gate to that item.

### Requirement 5: Active Route Auto-Expansion

**User Story:** As a user, I want the sidebar group containing the currently active page to be automatically expanded, so that I can always see where I am in the navigation hierarchy.

#### Acceptance Criteria

1. WHEN the Active_Route matches a Menu_Item inside a collapsed Menu_Group, THE Sidebar_Renderer SHALL expand that group's Collapse_Panel automatically.
2. WHEN the Active_Route changes to a Menu_Item in a different group, THE Sidebar_Renderer SHALL expand the new group's Collapse_Panel.
3. WHEN the Active_Route matches a top-level item, THE Sidebar_Renderer SHALL leave all group Collapse_Panels in their current state.
4. THE Sidebar_Renderer SHALL apply the `active` CSS class to the Menu_Item whose hash matches the Active_Route.

### Requirement 6: Smooth Collapse/Expand Animation

**User Story:** As a user, I want the group expand/collapse transitions to be smooth and consistent with the rest of the UI, so that the interaction feels polished.

#### Acceptance Criteria

1. THE Collapse_Panel SHALL animate its height transition using the SB Admin 2 collapse animation (200ms grow-in animation for expand, Bootstrap `collapsing` class for height transition).
2. THE Collapse_Panel SHALL use CSS transitions for the collapse animation rather than JavaScript-driven frame-by-frame animation.
3. THE Group_Header chevron indicator SHALL rotate smoothly when the collapse state changes.

### Requirement 7: Sidebar Dividers Between Groups

**User Story:** As a user, I want visual separators between sidebar groups, so that the grouping structure is clear even when groups are collapsed.

#### Acceptance Criteria

1. THE Sidebar_Renderer SHALL render a visual divider (using the existing SB Admin 2 `sidebar-divider` class) between consecutive groups and between a top-level item and a group.
2. THE Sidebar_Renderer SHALL render a section heading label above each group's Group_Header using the SB Admin 2 `sidebar-heading` class.

### Requirement 8: Sidebar Toggled (Minimized) State Compatibility

**User Story:** As a user, I want the grouped menu to work correctly when the sidebar is in its minimized/toggled state, so that the collapse behavior adapts to the narrow sidebar.

#### Acceptance Criteria

1. WHILE the sidebar is in the toggled (minimized) state, THE Collapse_Panel SHALL display as a popout flyout on hover (consistent with existing SB Admin 2 toggled sidebar behavior).
2. WHILE the sidebar is in the expanded state, THE Collapse_Panel SHALL display inline within the sidebar as an accordion.
3. WHEN the sidebar transitions between toggled and expanded states, THE Sidebar_Renderer SHALL preserve the logical collapse state of each group.

### Requirement 9: Keyboard Accessibility

**User Story:** As a user navigating with a keyboard, I want to be able to expand and collapse menu groups using keyboard controls, so that the sidebar remains accessible without a mouse.

#### Acceptance Criteria

1. THE Group_Header SHALL be focusable via keyboard tab navigation.
2. WHEN a Group_Header has focus and the user presses Enter or Space, THE Collapse_Panel SHALL toggle its expand/collapse state.
3. THE Group_Header SHALL have an appropriate `aria-expanded` attribute reflecting the current collapse state.
4. THE Collapse_Panel SHALL have an appropriate `aria-labelledby` attribute referencing its Group_Header.
