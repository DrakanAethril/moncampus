<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The top-level JSON objects held in a block of pasted text, in the order they appear.
 *
 * A teacher who asks a model for several quizzes at once gets several documents in one answer.
 * What comes back is never a JSON array of documents - it is objects one under another, nearly
 * always wrapped in ```json fences, usually with a sentence of the model's prose in between. So
 * `json_decode()` on the whole paste cannot be the reading: it sees one document, or nothing.
 *
 * The scan is deliberately structural rather than a regular expression. Both braces and quotes
 * occur *inside* the questions themselves - « Que fait } dans une regex ? » is a plausible énoncé,
 * and every escaped quote in a French sentence would end a naive string. Depth is therefore counted
 * outside strings only, and a backslash inside one always swallows the next character.
 *
 * It validates nothing: what it cuts out is handed to the format's own reader, which is the single
 * place that says whether a document is usable. A trailing object that never closes is handed over
 * as it stands for the same reason - the reader answers « ce document n'est pas du JSON valide »,
 * and dropping it silently would leave the teacher with a batch one quiz short and no message.
 */
final class JsonDocumentSplitter
{
    /** @return list<string> */
    public function split(string $text): array
    {
        $documents = [];
        $length = \strlen($text);
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                // Only meaningful inside an object: a stray quote in the model's prose would
                // otherwise swallow the document that follows it.
                if (null !== $start) {
                    $inString = true;
                }

                continue;
            }

            if ('{' === $char) {
                if (0 === $depth) {
                    $start = $i;
                }
                ++$depth;

                continue;
            }

            if ('}' === $char && $depth > 0) {
                --$depth;
                if (0 === $depth && null !== $start) {
                    $documents[] = substr($text, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        // An object that was opened and never closed - a truncated answer, or a fence the teacher
        // copied halfway. It is a document the reader will refuse by name.
        if (null !== $start) {
            $documents[] = rtrim(substr($text, $start));
        }

        return $documents;
    }
}
