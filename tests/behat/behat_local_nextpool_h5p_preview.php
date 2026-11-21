<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Real browser fixtures and checks for the Core H5P preview adapter (F16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_nextpool_h5p_preview extends behat_base {
    /**
     * Extend Core's real GreetingCard fixture with an image and playable audio, keeping its actual runtime.
     *
     * @Given /^a published H5P greeting card using "(div|iframe)" embedding exists$/
     * @param string $embedtype Core embed type.
     */
    public function greeting_card(string $embedtype): void {
        global $CFG;
        $path = make_request_directory() . '/greeting.h5p';
        copy($CFG->dirroot . '/h5p/tests/fixtures/greeting-card.h5p', $path);
        $zip = new ZipArchive();
        $zip->open($path);
        $librarypath = 'H5P.GreetingCard-1.0/library.json';
        $library = json_decode($zip->getFromName($librarypath), true);
        $library['embedTypes'] = [$embedtype];
        $zip->addFromString($librarypath, json_encode($library));
        $scriptpath = 'H5P.GreetingCard-1.0/greetingcard.js';
        $script = $zip->getFromName($scriptpath);
        $script .= <<<'JS'

(function() {
    const original = H5P.GreetingCard.prototype.attach;
    H5P.GreetingCard.prototype.attach = function(container) {
        original.call(this, container);
        const image = document.createElement('img');
        image.alt = 'Greeting card illustration';
        image.width = 96;
        image.height = 96;
        image.src = H5P.getPath('images/card.png', this.id);
        container[0].append(image);
        const audio = document.createElement('audio');
        audio.src = H5P.getPath('audio/card.wav', this.id);
        audio.loop = true;
        audio.controls = true;
        audio.setAttribute('aria-label', 'Greeting card audio');
        container[0].append(audio);
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Play card audio';
        button.onclick = () => audio.play().then(() => button.textContent = 'Playing card audio');
        container[0].append(button);
    };
})();
JS;
        $zip->addFromString($scriptpath, $script);
        $zip->addFromString('content/images/card.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
        $samples = str_repeat("\0", 160000);
        $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', strlen($samples)) . $samples;
        $zip->addFromString('content/audio/card.wav', $wav);
        $zip->close();
        testing_util::get_data_generator()->get_plugin_generator('local_nextpool')->create_published_item(
            ['title' => 'H5P greeting', 'type' => 'h5p'],
            file_get_contents($path),
            'greeting.h5p',
            true
        );
    }

    /**
     * Enter the rendered content, including the additional Core iframe when that embed type is selected.
     *
     * @When /^I enter the running H5P greeting card using "(div|iframe)" embedding$/
     * @param string $embedtype Expected Core embed type.
     */
    public function enter_card(string $embedtype): void {
        $frame = $this->find('css', 'iframe[title="Preview: H5P greeting"]');
        $this->execute_js_on_node($frame, "{{ELEMENT}}.name = 'nextpool-h5p-preview';");
        $this->execute('behat_general::switch_to_iframe', ['nextpool-h5p-preview']);
        $this->spin(function () {
            return $this->evaluate_script("Boolean(document.querySelector('.h5p-greetingcard,iframe.h5p-iframe'))");
        }, false, self::get_extended_timeout(), new \Behat\Mink\Exception\ExpectationException(
            'The H5P preview did not reach its expected state.',
            $this->getSession()
        ));
        $hasframe = $this->evaluate_script("Boolean(document.querySelector('iframe.h5p-iframe'))");
        if ($hasframe !== ($embedtype === 'iframe')) {
            throw new \Behat\Mink\Exception\ExpectationException('Wrong H5P embed type: ' . $embedtype, $this->getSession());
        }
        if ($hasframe) {
            $id = $this->evaluate_script("document.querySelector('iframe.h5p-iframe').id");
            $this->execute('behat_general::switch_to_iframe', [$id]);
        }
        $this->spin(function () {
            return $this->evaluate_script("Boolean(document.querySelector('.h5p-greetingcard .greeting-text'))");
        }, false, self::get_extended_timeout(), new \Behat\Mink\Exception\ExpectationException(
            'The H5P preview did not reach its expected state.',
            $this->getSession()
        ));
    }

    /**
     * Confirm real image decoding and HTML5 media loading through the permission-checked proxy.
     *
     * @Then /^the greeting card image and audio should be loaded$/
     */
    public function media_loaded(): void {
        try {
            $this->spin(function () {
                return $this->evaluate_script(<<<'JS'
(function() {
    const image = document.querySelector('img[alt="Greeting card illustration"]');
    const audio = document.querySelector('audio');
    return image?.complete && image.naturalWidth > 0 && audio?.readyState >= 2;
})()
JS);
            }, false, self::get_extended_timeout(), new \Behat\Mink\Exception\ExpectationException(
                'The H5P preview did not reach its expected state.',
                $this->getSession()
            ));
        } catch (\Exception $e) {
            $state = $this->evaluate_script(<<<'JS'
(function() {
    const image = document.querySelector('img');
    const audio = document.querySelector('audio');
    return JSON.stringify({image: image?.src, width: image?.naturalWidth, audio: audio?.src,
        ready: audio?.readyState, error: audio?.error?.message});
})()
JS);
            throw new \Behat\Mink\Exception\ExpectationException('H5P media did not load: ' . $state, $this->getSession());
        }
    }

    /**
     * Inspect actual media state in all reachable preview documents without altering it.
     *
     * @Then /^the H5P greeting audio should be "(playing|paused)"$/
     * @param string $state Expected state.
     */
    public function audio_state(string $state): void {
        $paused = $state === 'paused' ? 'true' : 'false';
        $this->spin(function () use ($paused) {
            return $this->evaluate_script(<<<JS
(function inspect(doc) {
    const audio = doc.querySelector('audio');
    if (audio) {
        return audio.readyState >= 2 && audio.paused === $paused;
    }
    return Array.from(doc.querySelectorAll('iframe')).some(frame => inspect(frame.contentDocument));
})(document)
JS);
        }, false, self::get_extended_timeout(), new \Behat\Mink\Exception\ExpectationException(
            'The H5P preview did not reach its expected state.',
            $this->getSession()
        ));
    }
}
