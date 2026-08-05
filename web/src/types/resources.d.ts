/**
 * Templates are read from resources/ rather than copied into web/, so the app
 * scores against the same document the server does.
 *
 * Declared as unknown on purpose. Letting TypeScript infer a literal type for
 * a 96 KB JSON document makes every check slow and produces a type that is
 * wrong the moment the instrument is revised. Callers cast to Template, which
 * is the shape scoring actually depends on.
 */
declare module '@resources/*.json' {
    const value: unknown
    export default value
}
