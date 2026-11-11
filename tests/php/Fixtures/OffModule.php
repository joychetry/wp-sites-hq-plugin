<?php
/**
 * Test module "off".
 *
 * @package WPSitesHQ
 */

namespace WPSitesHQ\Tests\Fixtures;

final class OffModule extends RecordingModule {

	public function id(): string {
		return 'off';
	}
}
