<?php

namespace App\Services\Ea;

/**
 * GraphQlParser — a small recursive-descent parser for the GraphQL subset
 * {@see GraphQlService} executes.
 *
 * Supported: `query`/`mutation` (named or anonymous), selection sets nested to
 * any depth, aliases (`total: applications`), arguments with string, number,
 * boolean, null, enum, list and object values, and variables (`$id` substituted
 * from the variables map).
 *
 * Not supported, and rejected with a clear message rather than mis-parsed:
 * fragments, directives, inline fragments, and subscriptions.
 *
 * The value of hand-writing it is that the failure messages are ours: an
 * integrator typing an unsupported construct gets told which construct, not a
 * stack trace from a dependency.
 */
class GraphQlParser
{
    private string $source = '';
    private int $position = 0;
    private array $variables = [];

    /**
     * @return array{operation:string, name:string|null, selections:array<int,array<string,mixed>>}
     */
    public function parse(string $query, array $variables = []): array
    {
        $this->source = $this->stripComments($query);
        $this->position = 0;
        $this->variables = $variables;

        // Directives and fragments are rejected up front so an unsupported
        // construct produces a sentence rather than a confusing parse error. The
        // `@` test is anchored to whitespace, because an email address inside a
        // string argument is legitimate and must not trip it.
        if (str_contains($this->source, 'fragment ')
            || str_contains($this->source, '...')
            || preg_match('/(?:^|[\s)])@[A-Za-z_]/', $this->source)) {
            throw new \InvalidArgumentException(
                'This endpoint implements a GraphQL subset that does not include fragments or directives. '.
                'Write the fields out explicitly.'
            );
        }

        $this->skipWhitespace();

        $operation = 'query';
        $name = null;

        if ($this->peekWord(['query', 'mutation', 'subscription'])) {
            $operation = $this->readName();
            if ($operation === 'subscription') {
                throw new \InvalidArgumentException('Subscriptions are not supported. Poll the query instead.');
            }
            $this->skipWhitespace();
            if ($this->current() !== '{' && $this->current() !== '(') {
                $name = $this->readName();
                $this->skipWhitespace();
            }
            // Variable definitions on the operation are accepted and skipped —
            // the values arrive in the variables map, and re-checking their
            // declared types here would add a type system for no benefit.
            if ($this->current() === '(') {
                $this->skipBalanced('(', ')');
                $this->skipWhitespace();
            }
        }

        $this->expect('{');
        $selections = $this->parseSelectionSet();

        return ['operation' => $operation, 'name' => $name, 'selections' => $selections];
    }

    /** @return array<int,array{name:string, alias:string|null, args:array<string,mixed>, selections:array}> */
    private function parseSelectionSet(): array
    {
        $selections = [];

        while (true) {
            $this->skipWhitespace();

            if ($this->position >= strlen($this->source)) {
                throw new \InvalidArgumentException('Unexpected end of document — a selection set is not closed.');
            }

            if ($this->current() === '}') {
                $this->position++;
                break;
            }

            $first = $this->readName();
            $this->skipWhitespace();

            $alias = null;
            $fieldName = $first;

            if ($this->current() === ':') {
                $this->position++;
                $this->skipWhitespace();
                $alias = $first;
                $fieldName = $this->readName();
                $this->skipWhitespace();
            }

            $args = [];
            if ($this->current() === '(') {
                $args = $this->parseArguments();
                $this->skipWhitespace();
            }

            $children = [];
            if ($this->current() === '{') {
                $this->position++;
                $children = $this->parseSelectionSet();
            }

            $selections[] = [
                'name' => $fieldName,
                'alias' => $alias,
                'args' => $args,
                'selections' => $children,
            ];

            $this->skipWhitespace();
            if ($this->current() === ',') {
                $this->position++;
            }
        }

        return $selections;
    }

    /** @return array<string,mixed> */
    private function parseArguments(): array
    {
        $this->expect('(');
        $arguments = [];

        while (true) {
            $this->skipWhitespace();
            if ($this->current() === ')') {
                $this->position++;
                break;
            }

            $name = $this->readName();
            $this->skipWhitespace();
            $this->expect(':');
            $this->skipWhitespace();
            $arguments[$name] = $this->parseValue();

            $this->skipWhitespace();
            if ($this->current() === ',') {
                $this->position++;
            }
        }

        return $arguments;
    }

    private function parseValue(): mixed
    {
        $this->skipWhitespace();
        $character = $this->current();

        if ($character === '$') {
            $this->position++;
            $name = $this->readName();
            if (! array_key_exists($name, $this->variables)) {
                throw new \InvalidArgumentException("Variable \${$name} was used but not supplied in `variables`.");
            }

            return $this->variables[$name];
        }

        if ($character === '"') {
            return $this->readString();
        }

        if ($character === '[') {
            $this->position++;
            $list = [];
            while (true) {
                $this->skipWhitespace();
                if ($this->current() === ']') {
                    $this->position++;
                    break;
                }
                $list[] = $this->parseValue();
                $this->skipWhitespace();
                if ($this->current() === ',') {
                    $this->position++;
                }
            }

            return $list;
        }

        if ($character === '{') {
            $this->position++;
            $object = [];
            while (true) {
                $this->skipWhitespace();
                if ($this->current() === '}') {
                    $this->position++;
                    break;
                }
                $key = $this->readName();
                $this->skipWhitespace();
                $this->expect(':');
                $object[$key] = $this->parseValue();
                $this->skipWhitespace();
                if ($this->current() === ',') {
                    $this->position++;
                }
            }

            return $object;
        }

        // Number, boolean, null, or a bare enum value.
        $raw = '';
        while ($this->position < strlen($this->source)
            && preg_match('/[A-Za-z0-9_.\-+]/', $this->current())) {
            $raw .= $this->current();
            $this->position++;
        }

        if ($raw === '') {
            throw new \InvalidArgumentException("Expected a value at offset {$this->position}.");
        }

        return match (strtolower($raw)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($raw) ? (str_contains($raw, '.') ? (float) $raw : (int) $raw) : $raw,
        };
    }

    private function readString(): string
    {
        $this->expect('"');

        // Block strings ("""…""") are accepted so a multi-line description in an
        // argument does not break the parse.
        if (substr($this->source, $this->position, 2) === '""') {
            $this->position += 2;
            $end = strpos($this->source, '"""', $this->position);
            if ($end === false) {
                throw new \InvalidArgumentException('Unterminated block string.');
            }
            $value = substr($this->source, $this->position, $end - $this->position);
            $this->position = $end + 3;

            return trim($value);
        }

        $value = '';
        while ($this->position < strlen($this->source)) {
            $character = $this->current();
            if ($character === '\\') {
                $next = $this->source[$this->position + 1] ?? '';
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $next,
                };
                $this->position += 2;

                continue;
            }
            if ($character === '"') {
                $this->position++;

                return $value;
            }
            $value .= $character;
            $this->position++;
        }

        throw new \InvalidArgumentException('Unterminated string.');
    }

    private function readName(): string
    {
        $this->skipWhitespace();
        $name = '';
        while ($this->position < strlen($this->source) && preg_match('/[A-Za-z0-9_]/', $this->current())) {
            $name .= $this->current();
            $this->position++;
        }

        if ($name === '') {
            $found = $this->position < strlen($this->source) ? "'{$this->current()}'" : 'end of document';
            throw new \InvalidArgumentException("Expected a field name at offset {$this->position}, found {$found}.");
        }

        return $name;
    }

    private function peekWord(array $words): bool
    {
        foreach ($words as $word) {
            if (str_starts_with(substr($this->source, $this->position), $word)) {
                return true;
            }
        }

        return false;
    }

    private function expect(string $character): void
    {
        $this->skipWhitespace();
        if ($this->current() !== $character) {
            $found = $this->position < strlen($this->source) ? "'{$this->current()}'" : 'end of document';
            throw new \InvalidArgumentException("Expected '{$character}' at offset {$this->position}, found {$found}.");
        }
        $this->position++;
    }

    private function skipBalanced(string $open, string $close): void
    {
        $this->expect($open);
        $depth = 1;
        while ($this->position < strlen($this->source) && $depth > 0) {
            $character = $this->current();
            if ($character === $open) {
                $depth++;
            }
            if ($character === $close) {
                $depth--;
            }
            $this->position++;
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->position < strlen($this->source)
            && (ctype_space($this->current()) || $this->current() === ',')) {
            $this->position++;
        }
    }

    private function current(): string
    {
        return $this->source[$this->position] ?? '';
    }

    private function stripComments(string $query): string
    {
        return (string) preg_replace('/#[^\n]*/', '', $query);
    }
}
