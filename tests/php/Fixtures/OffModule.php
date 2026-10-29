<?php
/**
 * Test module "off".
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests\Fixtures;

final class OffModule extends RecordingModule {

	public function id(): string {
		return 'off';
	}
}
