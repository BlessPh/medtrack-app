<?php

namespace App\Modules\Academic\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Nettoie les consignes enrichies avant leur stockage.
 *
 * L’éditeur n’a besoin que de balises de mise en forme et de listes. Toutes
 * les autres balises et tous les attributs sont supprimés afin que le
 * Front-end puisse restituer ce contenu sans permettre l’injection de script.
 */
class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'div', 'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    public function sanitize(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        // Les clients API sans éditeur visuel peuvent encore envoyer du texte
        // simple : leurs retours à la ligne sont conservés comme des <br>.
        if (! str_contains($html, '<')) {
            $html = nl2br(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="rich-text-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('rich-text-root');
        if (! $root) {
            return null;
        }

        $this->cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result) ?: null;
    }

    private function cleanChildren(DOMNode $parent): void
    {
        // Une copie est nécessaire, car la collection DOM est vivante pendant
        // la suppression ou le déplacement des nœuds.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object'], true)) {
                $parent->removeChild($node);
                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                $this->cleanChildren($parent);
                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $keepListType = $tag === 'ol'
                    && $attribute->name === 'type'
                    && in_array($attribute->value, ['1', 'a', 'A'], true);
                if (! $keepListType) {
                    $node->removeAttribute($attribute->name);
                }
            }

            $this->cleanChildren($node);
        }
    }
}
