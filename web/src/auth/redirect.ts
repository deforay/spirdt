/**
 * Where a signed-out visitor was trying to go, if it is a place at all.
 *
 * The router puts the intended path in the sign-in URL so a deep link survives
 * the detour through the form. That value is therefore whatever was in the link
 * somebody clicked, and honouring it unread is a phishing primitive rather than
 * a convenience: the address genuinely belongs to this installation, the form
 * is genuinely this form, the credentials genuinely reach this server — and the
 * visit ends on a page chosen by whoever wrote the link.
 *
 * So: a path on this origin, or nothing. A leading slash and no second one,
 * because "//example.org" is a protocol-relative URL that a browser reads as
 * another site while a careless check reads it as a path. Anything else is
 * discarded silently, which costs a deep link and never a session.
 *
 * Lives apart from the view for the usual reason — a component needs a DOM to
 * test and this needs a string.
 */
export function intendedPath(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null
    }

    // Backslashes because browsers have historically normalised them to
    // forward slashes in URLs, which turns "/\evil.example" into "//evil.example".
    if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) {
        return null
    }

    return value
}
