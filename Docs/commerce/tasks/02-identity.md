# Request protections, commerce identities and permissions

Scope: FND-03, IAM-01, IAM-02. Prerequisites are in the tracker. Read [contracts](../contracts.md) before changing actor/session behavior.

## FND-03

Existing references: [Auth.login()](../../../Website/core/Auth.php#L51), [createSession()](../../../Website/core/Auth.php#L161), [setRememberToken()](../../../Website/core/Auth.php#L176), [validateRememberToken()](../../../Website/core/Auth.php#L200), [DeveloperAccess.session()](../../../Website/core/DeveloperAccess.php#L17), [DeveloperAccess.login()](../../../Website/core/DeveloperAccess.php#L53), [Mailer.getMailer()](../../../Website/core/Mailer.php#L9), [bootstrap core loading](../../../Website/config/bootstarp.php#L19).

Implement a shared request validation/CSRF/session policy and repair the current Auth dependencies with `__DIR__`-based includes. Reuse current bootstrapped PDO; do not create a second connection during an order transaction. Keep Developer behavior and its separate session keys intact.

Authentication algorithm:

1. Establish strict session settings before output/start, using deployed HTTPS/proxy configuration, HttpOnly and SameSite. Create a random CSRF value; compare using a constant-time function on cookie-authenticated mutations.
2. Fetch user by normalized email; use generic failure response, password verification, persistent bounded throttling and enabled/locked/verified checks. Do not accept a requested role field from login/register input.
3. Regenerate session ID on successful auth, store commerce user ID only as identity reference, and fetch current status/grants for protected requests. Remove global timezone mutation from login.
4. Replace raw remember tokens with random selector/validator or hashed-token records containing user ID, hash, expiry and revocation. Rotate on use. Legacy raw tokens are revoked by an explicit upgrade migration; do not hash-and-preserve an unbounded legacy lifetime.
5. Logout/revocation removes the commerce session and its tokens without accidentally granting or inheriting Developer privileges.

Verification emails need hashed/expiring tokens and queued delivery rather than synchronous network work before customer registration is publicly enabled. FND-03 designs/hardens the token flow; use INF jobs once present. Keep transport disabled or use test transport before then.

Current `Mailer.getMailer()` contains embedded provider settings/credential. Do not copy their values into docs/tests. Move settings to noncommitted environment configuration and require the account operator to rotate any exposed credential before real delivery. Set transport deadlines and sanitize errors. Credential rotation is an external operator action, not evidence of local implementation.

Private-path algorithm: choose outside-document-root storage → restrict permissions → deny config/vendor/database/cache/import/log paths if exposed by hosting layout → verify HTTP probes on the target. Do not rely solely on development-server rules in `vayu`. Image policy is in [admin guide](07-admin.md#adm-02).

Checks: wrong/missing CSRF, login fixation attempt, disabled/locked user, stale remember token, revoked token, unauthenticated mutation, registration role escalation, cross-session token reuse, leaked error/secret file. Keep the existing [Developer regression](../../../Website/tests/DeveloperAccessTest.php#L1) passing.

## IAM-01

References: [existing users schema](../../../Website/database/migrations/UsersTable.php#L12), [Developer identity lookup](../../../Website/core/DeveloperAccess.php#L32), [Developer provisioning](../../../Website/core/DeveloperAccess.php#L83). Proposed additions: ordered role/grant migrations and `core/CommerceAccess.php`.

Schema: roles unique name, permissions unique key, user_roles unique `(user_id, role_id)`, role_permissions unique `(role_id, permission_id)`. FK user type matches users_tbl. Seed grant definitions/roles idempotently; seed no usable staff password. Existing `users_tbl.role` is legacy information, not automatic wildcard permission. Map existing staff grants through an explicit reviewed migration/provisioning command.

Grant algorithm: obtain commerce session ID → read enabled/verified user → join current pivot grants → test exact permission → reject missing grant. Do not cache permissions indefinitely in the PHP session. Provisioning uses operator-supplied password, password hashing, validated unique email and explicit role assignment in one transaction. First admin is a CLI/operator setup action, not public signup.

Checks: customer with legacy role string gains no accidental admin grant; staff with products-view cannot edit; role revocation applies on next request; disabled users are denied; existing Developer account does not become commerce staff; duplicated seed runs preserve unique pivots.

## IAM-02

Reference: [Developer.start()](../../../Website/app/bridge/Developer.php#L5) shows controller guard usage, but commerce must check its own actor/grants. Proposed controller guards call CommerceAccess; business services validate privileged operations as well.

Ownership algorithm: scope SQL by both record ID and server principal (`WHERE id = :id AND user_id = :actor`), or guest token hash for guest carts. Return 404 for another customer's record. A staff operation requires its separate capability and audit, never a client-supplied `is_admin` flag.

Audit schema includes actor user ID, action, target type/ID, operation key, request ID, UTC time and bounded redacted changes. Write required privileged audit rows inside the mutation transaction. If required audit write fails, roll back. Do not store passwords, tokens, arbitrary request bodies or full address blobs in activity logs.

Checks: HTML and JSON routes use identical grants, customer A cannot read/write B's cart/address/order, staff write without grant is denied, revoked staff loses access, failed SQL mutation writes no success audit, accepted mutation writes exactly one audit for its operation key. UI navigation filtering is convenience; backend checks remain mandatory.
