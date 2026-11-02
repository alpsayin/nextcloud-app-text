<?php

namespace OCA\Text\Service;

use Test\TestCase;

class LabelServiceTest extends TestCase {
	private $encodingService;

	protected function setUp(): void {
		parent::setUp();
		$this->encodingService = new EncodingService();
	}

	/**
	 * Attempt to decode the file using the default decoding order.
	 * For files with encodings not included in the COMMON_ENCODINGS array encoding to UTF-8 will fail.
	 * We then do the conversion again after setting the mb_detect_order value: all conversions should succeed.
	 * @dataProvider dataFileEncodings
	 */
	public function testEncoding(string $file, string $encoding) {
		$utf8String = $this->encodingService->encodeToUtf8(file_get_contents($file));

		// If encoding is not part of the default encodings we can expect it to fail
		// It might still succeed because encoding detection is not precise.
		if ($utf8String || $this->isSupportedEncoding($encoding)) {
			$this->assertNotNull($utf8String);
			$this->assertNotFalse(mb_detect_encoding($utf8String, 'UTF-8', true));
		}

		$originalOrder = mb_detect_order();
		$this->assertNotFalse(mb_detect_order($encoding));

		$utf8String = $this->encodingService->encodeToUtf8(file_get_contents($file));
		$this->assertNotNull($utf8String);
		$this->assertNotFalse(mb_detect_encoding($utf8String, 'UTF-8', true));

		mb_detect_order($originalOrder);
	}

	/**
	 * If the encoding is in the list of common encodings we should be able to detect an encoding (it might not be the
	 * correct encoding due to detection inaccuracies). If not, add the encoding to mb_detect_order.
	 * @dataProvider dataFileEncodings
	 */
	public function testDetection(string $file, string $encoding) {
		$detectedEncoding = $this->encodingService->detectEncoding(file_get_contents($file));
		if ($this->isSupportedEncoding($encoding)) {
			$this->assertNotNull($detectedEncoding);
		}

		$originalOrder = mb_detect_order();
		$this->assertNotFalse(mb_detect_order($encoding));

		$detectedEncoding = $this->encodingService->detectEncoding(file_get_contents($file));
		$this->assertEquals($encoding, $detectedEncoding);

		mb_detect_order($originalOrder);
	}

	public function dataFileEncodings(): array {
		return [
			['./tests/data/iso-8859-15.txt', 'ISO-8859-15'],
			['./tests/data/big5.txt', 'BIG-5'],
			['./tests/data/cp936.txt', 'CP936'],
			['./tests/data/utf-16.txt', 'UTF-16LE'],
			['./tests/data/iso-8859-5.txt', 'ISO-8859-5'],
		];
	}

	/**
	 * @dataProvider dataSanitizeForXml
	 */
	public function testSanitizeForXml(string $input, string $expected) {
		$this->assertSame($expected, $this->encodingService->sanitizeForXml($input));
	}

	public function dataSanitizeForXml(): array {
		return [
			'plain markdown is untouched' => ["# Title\n\nSome *text*", "# Title\n\nSome *text*"],
			'tab, CR and LF are allowed' => ["a\tb\r\nc", "a\tb\r\nc"],
			'multibyte characters are allowed' => ['Grüße 😀 €', 'Grüße 😀 €'],
			'existing replacement characters are kept' => ["a\u{FFFD}b", "a\u{FFFD}b"],
			'C0 control characters are replaced' => ["a\x00b\x10c\x16d\x1ce\x1f", "a\u{FFFD}b\u{FFFD}c\u{FFFD}d\u{FFFD}e\u{FFFD}"],
			'vertical tab and form feed are replaced' => ["a\x0Bb\x0Cc", "a\u{FFFD}b\u{FFFD}c"],
			'U+FFFE and U+FFFF are replaced' => ["a\u{FFFE}b\u{FFFF}c", "a\u{FFFD}b\u{FFFD}c"],
		];
	}

	public function testSanitizeForXmlProducesWellFormedXml() {
		// Content shaped like a Readme.md left behind by server-side encryption
		$content = "HBEGIN:oc_encryption_module:OC_DEFAULT_MODULE:HEND\x10\x16\x1c" . implode('', array_map('chr', range(0, 255)));

		$sanitized = $this->encodingService->sanitizeForXml($content);

		$this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument('1.0', 'UTF-8');
		$writer->writeElement('rich-workspace', $sanitized);
		$writer->endDocument();
		$document = new \DOMDocument();
		$this->assertTrue($document->loadXML($writer->outputMemory()));
	}

	private function isSupportedEncoding(string $encoding): bool {
		return in_array($encoding, EncodingService::COMMON_ENCODINGS, true)
			|| isset(EncodingService::UTF_BOMs[$encoding]);
	}
}
