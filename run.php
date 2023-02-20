<?php
/**
 * Benford law checking over GitHub repositories: IDs, stars, forks, issues count.
 *
 * Code style: PSR-12T (PSR-12 with SmartTabs).
 *
 * @author MaximAL
 * @date 2023-02-20
 * @time 13:40
 * @since 2023-02-20 First version.
 *
 * @copyright ©  MaximAL, Sijeko  2023
 * @link https://github.com/maximal/github-benford
 * @link https://maximals.ru
 * @link https://sijeko.ru
 */

namespace Maximal\GitHubBenfordStatistics;


// Run only in CLI mode, if explicitly executed
if (isset($argv) && count($argv) > 0 && realpath($argv[0]) === __FILE__) {
	exit((new App($argv))->run());
}


/**
 * Main app class
 *
 * @noinspection AutoloadingIssuesInspection
 */
class App
{
	private string $gitHubAccessToken = '';
	private array $argv;

	public function __construct(array $argv)
	{
		$this->argv = $argv;
	}

	public function run(): int
	{
		$timeStart = microtime(true);

		if (count($this->argv) < 3) {
			fwrite(
				STDERR,
				'Usage:  php ' . $this->argv[0] .
				'  <GitHub access token>' .
				'  <repo language>' .
				'  [repo count; 10...1000; default: 1000]' . PHP_EOL
			);
			return 1;
		}

		$this->gitHubAccessToken = trim($this->argv[1]);
		$language = trim($this->argv[2]);
		$count = (int)($this->argv[3] ?? 1000);

		if ($count < 10) {
			echo 'count < 10, defaulting to 10', PHP_EOL;
			$count = 10;
		} elseif ($count > 1000) {
			echo 'count > 1000, defaulting to 1000', PHP_EOL;
			$count = 1000;
		}

		$ids = [];
		$stars = [];
		$forks = [];
		$issues = [];
		foreach ($this->getRepositories($language, $count) as $repo) {
			$ids[] = (int)$repo->id;
			$stars[] = (int)$repo->stargazers_count;
			$forks[] = (int)$repo->forks;
			$issues[] = (int)$repo->open_issues;
		}

		$idsFirstDigitStats = $this->getDigitStats($ids);
		$starsFirstDigitStats = $this->getDigitStats($stars);
		$forksFirstDigitStats = $this->getDigitStats($forks);
		$issuesFirstDigitStats = $this->getDigitStats($issues);

		echo 'Writing IDs statistics to ids.html ...', PHP_EOL;
		$this->writeDigitStats(
			$idsFirstDigitStats,
			'ids.html',
			'First digits of IDs over ' . $count . ' repositories of ' . $language . ' language'
		);

		echo 'Writing stars statistics to stars.html ...', PHP_EOL;
		$this->writeDigitStats(
			$starsFirstDigitStats,
			'stars.html',
			'First digits of stars count over ' . $count . ' repositories of ' . $language . ' language'
		);

		echo 'Writing forks statistics to forks.html ...', PHP_EOL;
		$this->writeDigitStats(
			$forksFirstDigitStats,
			'forks.html',
			'First digits of forks count over ' . $count . ' repositories of ' . $language . ' language'
		);

		echo 'Writing open issues statistics to issues.html ...', PHP_EOL;
		$this->writeDigitStats(
			$issuesFirstDigitStats,
			'issues.html',
			'First digits of open issues count over ' . $count . ' repositories of ' . $language . ' language'
		);

		$timeDiff = microtime(true) - $timeStart;
		echo 'Time: ', sprintf('%.3f sec.', $timeDiff), PHP_EOL;

		return 0;
	}

	/**
	 * Write first digit statistics to HTML file.
	 *
	 * @throws \JsonException
	 */
	private function writeDigitStats(array $array, string $filename, string $title): void
	{
		$digits = array_map(static fn($item) => '' . $item, array_keys($array));
		$html = sprintf(
			'<html>
<head>
	<title>%s</title>
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div><canvas id="digit-chart"></canvas></div>

<script>
	const ctx = document.getElementById(\'digit-chart\');
	new Chart(ctx, {
		type: \'bar\',
		data: {
			labels: %s,
			datasets: [{
				label: %s,
				data: %s,
				borderWidth: 1
			}]
		},
		options: {
			scales: {
				y: {
					beginAtZero: true
				}
			}
		}
	});
</script>

<footer>
	<p>
		GitHub Benford law checking
		&middot;
		© MaximAL, Sijeko 2023
		&middot;
		<a href="https://github.com/maximal/github-benford">GitHub repository</a>
	</p>
</footer>
</body>
</html>',
			htmlspecialchars($title),
			json_encode($digits, JSON_THROW_ON_ERROR),
			json_encode($title, JSON_THROW_ON_ERROR),
			json_encode(array_values($array), JSON_THROW_ON_ERROR)
		);
		file_put_contents($filename, $html);
	}

	/**
	 * Get first digits statistics for Benford’s law checking
	 */
	private function getDigitStats(array $array): array
	{
		$result = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0];
		foreach ($array as $item) {
			if ($item > 0) {
				$firstDigit = (int)substr(trim((string)$item), 0, 1);
				$result[$firstDigit]++;
			}
		}
		return $result;
	}

	/**
	 * Get top GitHub repositories of given programming language
	 */
	private function getRepositories(string $language, int $count = 100): \Generator
	{
		$total = 0;
		$page = 1;
		do {
			$url = 'https://api.github.com/search/repositories?q=language:' .
				urlencode($language) . '&per_page=100&page=' . $page;
			$json = $this->gitHubGet($url);
			foreach (json_decode($json, false)->items as $repo) {
				yield $repo;
				$total++;
				if ($total >= $count) {
					break;
				}
			}
			$page++;
		} while ($total < $count);
	}

	/**
	 * Call GitHub API with GET method
	 */
	private function gitHubGet(string $url): string
	{
		$curl = curl_init();
		echo 'Getting: ', $url, ' ... ';
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_HTTPHEADER => [
				'Authorization: Bearer ' . $this->gitHubAccessToken,
				'User-Agent: Repository statistics',
				'Content-Type: application/json; charset=utf-8',
				'Accept: application/json',
				'X-GitHub-Api-Version: 2022-11-28',
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HEADER => false,
		]);
		$result = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		echo $code, PHP_EOL;
		if ($code < 200 || $code > 299) {
			fwrite(
				STDERR,
				'Response code: ' . $code . PHP_EOL .
				'Response body: ' . $result . PHP_EOL
			);
		}
		curl_close($curl);
		return $result;
	}
}
