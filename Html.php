<?php

namespace App\Validate;

use DOMDocument;
use DOMElement;
use DOMXPath;

class Html
{
	/**
	 * Automatycznie domyka tagi HTML i naprawia strukturę dokumentu.
	 *
	 * @param string|null $html Kod HTML do sformatowania.
	 * @param array $tagsToRemove Lista tagów do całkowitego usunięcia (np. ['script', 'code']).
	 * @param bool $onEvents Usuwa onclick, onload, on... events.
	 * @param bool $jsEvents Usuwa javascript: events.
	 * @return string Sformatowany i bezpieczny kod HTML.
	 */
	static public function format(?string $html, array $tagsToRemove = ['script'], bool $onEvents = true, bool $jsEvents = true): string
	{
		if (empty($html)) {
			return '';
		}

		$dom = new DOMDocument();

		// Ignorujemy błędy składniowe niepoprawnego HTML
		libxml_use_internal_errors(true);

		// Wczytujemy HTML z zachowaniem kodowania UTF-8
		// Flagi zapobiegają dodawaniu domyślnych tagów <html> i <!DOCTYPE>
		// tu 'HTML-ENTITIES' w mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), // deprecated 8.2+
		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		$xpath = new DOMXPath($dom);

		// Usuwanie niechcianych tagów jednym zapytaniem XPath
		if (!empty($tagsToRemove)) {
			$xpathExpression = '//' . implode(' | //', $tagsToRemove);
			$elementsToRemove = $xpath->query($xpathExpression);

			if ($elementsToRemove) {
				for ($i = $elementsToRemove->length - 1; $i >= 0; $i--) {
					$node = $elementsToRemove->item($i);
					// Bezpieczne sprawdzenie rodzica przed usunięciem
					if ($node && $node->parentNode) {
						$node->parentNode->removeChild($node);
					}
				}
			}
		}

		if ($onEvents) {
			// Oczyszczanie atrybutów inline JS (np. onerror, onclick)
			$allElementsWithAttributes = $xpath->query('//*[@*]');
			if ($allElementsWithAttributes) {
				foreach ($allElementsWithAttributes as $element) {
					$attributesToRemove = [];
					foreach ($element->attributes as $attribute) {
						if (str_starts_with(strtolower($attribute->name), 'on')) {
							$attributesToRemove[] = $attribute->name;
						}
					}
					foreach ($attributesToRemove as $attrName) {
						$element->removeAttribute($attrName);
					}
				}
			}
		}

		// Blokowanie linków i zasobów typu "javascript:..."
		if ($jsEvents) {
			// Szukamy tagów, które najczęściej mogą uruchomić protokół javascript: lub data:
			$urlElements = $xpath->query('//a[@href] | //area[@href] | //img[@src] | //video[@src or @poster] | //audio[@src]');

			if ($urlElements) {
				foreach ($urlElements as $element) {
					if (!$element instanceof DOMElement) {
						continue;
					}
					// Sprawdzamy atrybut href (dla linków)
					if ($element->hasAttribute('href')) {
						$href = trim($element->getAttribute('href'));
						if (str_starts_with(strtolower($href), 'javascript:')) {
							$element->setAttribute('href', '#'); // Neutralizujemy link
						}
					}

					// Sprawdzamy atrybut src / poster (dla multimediów)
					foreach (['src', 'poster'] as $attr) {
						if ($element->hasAttribute($attr)) {
							$value = trim($element->getAttribute($attr));
							if (str_starts_with(strtolower($value), 'javascript:')) {
								$element->removeAttribute($attr); // Usuwamy złośliwe źródło
							}
						}
					}
				}
			}
		}

		libxml_clear_errors();

		return trim($dom->saveHTML());
	}
}
