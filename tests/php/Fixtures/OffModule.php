<?php
/**
 * Test module "off".
 *
 * @package WpPluginStarter
 */

namespace WpPluginStarter\Tests\Fixtures;

final class OffModule extends RecordingModule {

	public function id(): string {
		return 'off';
	}
}
