> **تحديث 18 أيلول 2026 — D52–D55:** Fill Vacancy يحتاج شاغراً متاحاً قائماً؛ اختيار تلقائي للواحد واختيار صريح عند التعدد. أب CreatePosition إداري أو لديه فريق نشط ضمن الشجرة. مدير البرنامج/المشروع مقيّد ببرنامج منصبه وعلاقاته الجغرافية ومراكزه، ولا ينشئ Department Manager. هذه القيود تخصص وصف D49/D51 السابق؛ نطاق الرؤية ومنع HR من Normal Request باقيان. راجع سجل القرارات.

# Recruitment authorization

Recruitment access always has two layers:

1. `User::hasActivePermission()` checks the permission of the user's current role.
2. A policy calls `RecruitmentScopeResolver` to check the specific record or workflow step.

Neither layer is sufficient by itself. Route middleware protects page entry, while policies and scoped queries prevent IDOR/BOLA when a caller changes an ID or invokes an action directly.

## Scoped reads

All recruitment lists, exports, counters, and selectors must start from a user-scoped query:

```php
$requests = RecruitmentRequest::query()
    ->visibleTo($user)
    ->latest()
    ->paginate();
```

Equivalent `visibleTo($user)` scopes exist on `Vacancy`, `HiringCampaign`, `JobPost`, `ApplicationForm`, `PublicRecruitmentLink`, and `RecruitmentRequestEvent`.

Never load an unrestricted record by an ID supplied by the browser and then rely on a hidden button. Authorize the resolved model:

```php
Gate::authorize('view', $recruitmentRequest);
Gate::authorize('update', $recruitmentRequest);
```

## Request creation

Opening the normal create screen checks the active manager/general-manager role, permission and grade. HR cannot use this path, including HR with grade 1, 3 or 5 (D50). `directOpen` remains a separate permission and path.

Normal request creation uses the complete administrative subtree (D49), not city/program and not D30's one-level visibility. Always authorize the selected target or proposed parent at persistence time:

```php
Gate::authorize('create', RecruitmentRequest::class); // page entry only
Gate::authorize('createForPosition', [RecruitmentRequest::class, $targetPosition]);
// CreatePosition: direct_manager_id is the proposed parent.
Gate::authorize('createUnderPosition', [RecruitmentRequest::class, $parentPosition]);
```

## Workflow decisions

`manageablePositionIds($user)` returns all descendants, excluding the user's own position. `manageablePositionIds($user, includeSelf: true)` also includes that root for CreatePosition. Selectors and server validation use the same `scopeManageablePositions()` boundary. Technical reporting lines grant no access; cycles terminate. Existing request visibility uses `scopeRequests()` unchanged.

Approval, rejection, and requesting changes are authorized against the resolved workflow instance step—not only against the request:

```php
Gate::authorize('approve', $workflowInstanceStep);
Gate::authorize('reject', $workflowInstanceStep);
Gate::authorize('requestChanges', $workflowInstanceStep);
```

The check requires all of the following at action time:

- the corresponding active-role permission;
- an undecided step;
- the acting user is the stored assignee;
- the active role still matches the assigned role;
- the user's current organizational assignment still matches the stored scope.

Missing or unknown scope data fails closed. Historical events remain interpretable through their stored actor, role, and scope fields even after a user's current role changes.
