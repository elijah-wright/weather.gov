<?php

namespace Drupal\weather_data\Service;

use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;

/**
 * A service class for fetching weather data.
 */
class WeatherDataService {
  /**
   * Mapping of legacy API icon paths to new icons and conditions text.
   *
   * @var legacyMapping
   */
  private $legacyMapping;

  /**
   * A catch-all default icon to show.
   *
   * @var string
   */
  private $defaultIcon;

  /**
   * A catch-all conditions label to display.
   *
   * @var defaultConditions
   */
  private $defaultConditions;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface client
   */
  private $client;

  /**
   * Translation provider.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface t
   */
  private $t;

  /**
   * Constructor.
   */
  public function __construct(ClientInterface $httpClient, TranslationInterface $t) {
    $this->client = $httpClient;
    $this->t = $t;
    $this->defaultIcon = "nodata.svg";
    $this->defaultConditions = "No data";

    $this->legacyMapping = json_decode(
      file_get_contents(
        __DIR__ . "/legacyMapping.json"
      )
    );
  }

  /**
   * Return a condition stripped of any parentheticals.
   *
   * @return string
   *   A condition text with any parenthetical
   *    statements removed
   */
  private function removeParenthetical($str) {
    $parts = explode("(", $str);
    return $parts[0];
  }

  /**
   * Return only the periods that are after today.
   *
   * This private method will filter the forecast periods
   * to only include periods whose startTime corresponds to
   * "tomorrow" or later.
   *
   * The optional argument $limitDays, if set,
   * should be an integer specifying the max number
   * of days to return. Note that a day is two periods
   * (daytime and overnight) combined.
   *
   * @return array
   *   An array of forecast period data filtered as described
   */
  public function filterToFutureDays($data, $now, $limitDays = NULL) {
    $tomorrow = $now->modify('tomorrow');
    $result = array_filter($data, function ($period) use (&$tomorrow) {
      $startTime = \DateTimeImmutable::createFromFormat(
        \DateTimeInterface::ISO8601,
        $period->startTime
      );
      return $startTime > $tomorrow;
    });

    // Each period here is half a day
    // (the morning or the night), so
    // we need double the limit periods.
    if ($limitDays != NULL) {
      return array_values(
        array_slice($result, 0, $limitDays * 2)
      );
    }

    return array_values($result);
  }

  /**
   * Gets a unique key identifying the conditions described in an observation.
   *
   * @param object $observation
   *   An observation from api.weather.gov.
   *
   * @return string
   *   A key uniquely identifying the current conditions.
   */
  public function getApiObservationKey($observation) {
    /* The icon path from the API is of the form:
    https://api.weather.gov/icons/land/day/skc
    - OR -
    https://api.weather.gov/icons/land/day/skc/hurricane

    The last two or three path segments are the ones we need
    to identify the current conditions. This is because there can be
    two simultaneous conditions in the legacy icon system.

    For now, we use the _first_ condition given in the path as the canonical
    condition for the key.
     */
    $icon = $observation->icon;

    if ($icon == NULL or strlen($icon) == 0) {
      return "no data";
    }

    $url = parse_url($observation->icon);
    $path = $url["path"];
    $path = explode("/", $path);

    // An icon url, when split to path parts,
    // with have either 5 or 6 parts.
    // Thus we need to trim from the end by
    // either 2 or 3 each time.
    if (count($path) == 6) {
      $path = array_slice($path, -3, 2);
    }
    else {
      $path = array_slice($path, -2);
    }

    $path = array_map(function ($piece) {
      return preg_replace("/,.*$/", "", $piece);
    }, $path);

    $apiConditionKey = implode("/", $path);

    return $apiConditionKey;
  }

  /**
   * Get a WFO grid from a latitude and longitude.
   */
  public function getGridFromLatLon($lat, $lon) {
    try {
      $locationResponse = $this->client->get("https://api.weather.gov/points/$lat,$lon");
      $locationMetadata = json_decode($locationResponse->getBody());

      return [
        "wfo" => $locationMetadata->properties->gridId,
        "gridX" => $locationMetadata->properties->gridX,
        "gridY" => $locationMetadata->properties->gridY,
        "location" => $locationMetadata->properties->relativeLocation->properties->city,
      ];

    }
    catch (\Throwable $e) {
      // Need to check the error so we know whether we ought to log something.
      // But not yet. I am too excited about this location stuff right now.
      return NULL;
    }
  }

  /**
   * Get the current weather conditions at a location.
   *
   * The location is taken from the provided route.
   *
   * @return array
   *   The current conditions as an associative array, or
   *   NULL if no route is provided, or the provided route is not on the grid.
   */
  public function getCurrentConditions($route) {

    // If this isn't a grid route, don't do anything. We can only respond to
    // requests on the grid.
    if ($route->getRouteName() != "weather_routes.grid") {
      return NULL;
    }

    $data = &drupal_static(__FUNCTION__);

    if (!isset($data)) {
      // Since we're on the right kind of route, pull out the data we need.
      $wfo = $route->getParameter("wfo");
      $gridX = $route->getParameter("gridX");
      $gridY = $route->getParameter("gridY");
      $location = $route->getParameter("location");

      date_default_timezone_set('America/New_York');

      $obsStationsResponse = $this->client->get("https://api.weather.gov/gridpoints/$wfo/$gridX,$gridY/stations");
      $obsStationsMetadata = json_decode($obsStationsResponse->getBody());

      $observationStation = $obsStationsMetadata->features[0];

      $obsResponse = $this->client->get($observationStation->id . "/observations?limit=1");
      $obs = json_decode($obsResponse->getBody())->features[0]->properties;

      $timestamp = \DateTime::createFromFormat(\DateTimeInterface::ISO8601, $obs->timestamp);

      $feelsLike = $obs->heatIndex->value;
      if ($feelsLike == NULL) {
        $feelsLike = $obs->windChill->value;
      }
      if ($feelsLike == NULL) {
        $feelsLike = $obs->temperature->value;
      }
      $feelsLike = 32 + (9 * $feelsLike / 5);

      $obsKey = $this->getApiObservationKey($obs);

      $description = $this->legacyMapping->$obsKey->conditions;

      // The cardinal and ordinal directions. North goes in twice because it
      // sits in two "segments": -22.5° to 22.5°, and 337.5° to 382.5°.
      $directions = ["north", "northeast", "east", "southeast", "south",
        "southwest", "west", "northwest", "north",
      ];
      $shortDirections = ["N", "NE", "E", "SE", "S", "SW", "W", "NW", "N"];

      // 1. Whatever degrees we got from the API, constrain it to 0°-360°.
      // 2. Add 22.5° to it. This accounts for north starting at -22.5°
      // 3. Use integer division by 45° to see which direction index this is.
      // This indexes into the two direction name arrays above.
      $directionIndex = intdiv(intval(($obs->windDirection->value % 360) + 22.5, 10), 45);

      $data = [
        'conditions' => [
          'long' => $this->t->translate($description),
          'short' => $this->t->translate($description),
        ],
        // C to F.
        'feels_like' => (int) round($feelsLike),
        'humidity' => (int) round($obs->relativeHumidity->value ?? 0),
        'icon' => $this->legacyMapping->$obsKey->icon,
        'location' => $location,
        // C to F.
        'temperature' => (int) round(32 + (9 * $obs->temperature->value / 5)),
        'timestamp' => [
          'formatted' => $timestamp->format("l g:i A T"),
          'utc' => (int) $timestamp->format("U"),
        ],
        'wind' => [
          // Kph to mph.
          'speed' => (int) round($obs->windSpeed->value * 0.6213712),
          'angle' => $obs->windDirection->value,
          'direction' => $directions[$directionIndex],
          'shortDirection' => $shortDirections[$directionIndex],
        ],
      ];
    }

    return $data;
  }

  /**
   * Get the hourly forecast for a location.
   *
   * The location is taken from the provided route. Note that the $now object
   * should *NOT* be set. It's a dependency injection hack so we can mock the
   * current date/time.
   *
   * @return array
   *   The hourly forecast as an associative array, or NULL if no route is
   *   provided, or the provided route is not on the grid.
   */
  public function getHourlyForecast($route, $now = FALSE) {
    // If this isn't a grid route, don't do anything. We can only respond to
    // requests on the grid.
    if ($route->getRouteName() != "weather_routes.grid") {
      return NULL;
    }

    if (!($now instanceof \DateTimeImmutable)) {
      $now = new \DateTimeImmutable();
    }

    // Since we're on the right kind of route, pull out the data we need.
    $wfo = $route->getParameter("wfo");
    $gridX = $route->getParameter("gridX");
    $gridY = $route->getParameter("gridY");

    date_default_timezone_set('America/New_York');

    $forecast = $this->client->get("https://api.weather.gov/gridpoints/$wfo/$gridX,$gridY/forecast/hourly");
    $forecast = json_decode($forecast->getBody());

    // Get a point from the WFO grid. Any will do. We will use that to fetch the
    // appropriate timezone from the /points API endpoint.
    $point = $forecast->geometry->coordinates[0][0];
    $timezone = $this->client->get("https://api.weather.gov/points/$point[1],$point[0]");
    $timezone = json_decode($timezone->getBody());
    $timezone = $timezone->properties->timeZone;

    $forecast = $forecast->properties->periods;

    // Toss out any time periods in the past.
    $forecast = array_filter($forecast, function ($period) use (&$now) {
      $then = \DateTimeImmutable::createFromFormat(
        \DateTimeInterface::ISO8601_EXPANDED,
        $period->startTime
      );
      $diff = $now->diff($then, FALSE);

      return $diff->invert != 1;
    });

    // Now map all those forecast periods into the structure we want.
    $forecast = array_map(function ($period) use (&$timezone) {

      // This closure needs access to the $timezone variable about. The easiest
      // way I found to do it was using it by reference.
      // From the start period of the time, parse it as an ISO8601 string and
      // then format it into just the "Hour AM/PM" format (e.g., "8 PM")
      $timestamp = \DateTimeImmutable::createFromFormat(
        \DateTimeInterface::ISO8601_EXPANDED,
        $period->startTime
      )->setTimeZone(new \DateTimeZone($timezone))
        ->format("g A");

      $obsKey = $this->getApiObservationKey($period);

      return [
        "conditions" => $this->legacyMapping->$obsKey->conditions,
        "icon" => $this->legacyMapping->$obsKey->icon,
        "probabilityOfPrecipitation" => $period->probabilityOfPrecipitation->value,
        "time" => $timestamp,
        "temperature" => $period->temperature,
      ];
    }, $forecast);

    // Reindex the array. array_filter maintains indices, so it can result in
    // holes in the array. Bizarre behavior choice, but okay...
    return array_values($forecast);
  }

  /**
   * Get the daily forecast for a location.
   *
   * The location is taken from the provided route. Note that the $now object
   * should *NOT* be set. It's a dependency injection hack so we can mock the
   * current date/time.
   *
   * @return array
   *   The daily forecast as an associative array, or NULL if no route is
   *   provided, or the provided route is not on the grid.
   */
  public function getDailyForecast($route, $now = FALSE, $defaultDays = 5) {
    // If this isn't a grid route, don't do anything. We can only respond to
    // requests on the grid.
    if ($route->getRouteName() != "weather_routes.grid") {
      return NULL;
    }

    // We pull the grid information from the
    // route URI.
    $wfo = $route->getParameter("wfo");
    $gridX = $route->getParameter("gridX");
    $gridY = $route->getParameter("gridY");

    $forecast = $this->client->get("https://api.weather.gov/gridpoints/$wfo/$gridX,$gridY/forecast");
    $forecast = json_decode($forecast->getBody());

    $periods = $forecast->properties->periods;

    // In order to keep the time zones straight,
    // we set the "current" (now) time to be
    // the startTime of the first period.
    if (!($now instanceof \DateTimeImmutable)) {
      $now = \DateTimeImmutable::createFromFormat(
        \DateTimeInterface::ISO8601_EXPANDED,
        $periods[0]->startTime
      );
    }
    $periods = $this->filterToFutureDays($periods, $now, $defaultDays);

    // Periods are either daytime or nighttime
    // We can zip them together as pairs.
    $dayNightPairs = array_chunk($periods, 2);

    $periods = array_map(function ($periodPair) {
      $daytime = $periodPair[0];
      $overnight = $periodPair[1];

      // Daily forecast cards require the three-letter
      // abrreviated form of the day name.
      $startTime = \DateTimeImmutable::createFromFormat(
        \DateTimeInterface::ISO8601,
        $daytime->startTime
      );
      $shortDayName = $startTime->format('D');

      // Get any mapped condition and/or icon values.
      $obsKey = $this->getApiObservationKey($daytime);

      // The short forecast name should be mapped to
      // the legacyMapping and translated.
      $shortForecast = $this->legacyMapping->$obsKey->conditions;
      $shortForecast = $this->removeParenthetical($shortForecast);

      $daytimeForecast = [
        'shortDayName' => $shortDayName,
        'startTime' => $daytime->startTime,
        'shortForecast' => $this->t->translate($shortForecast),
        'icon' => $this->legacyMapping->$obsKey->icon,
        'temperature' => $daytime->temperature,
        'probabilityOfPrecipitation' => $daytime->probabilityOfPrecipitation->value,
      ];

      $overnightForecast = [
        'temperature' => $overnight->temperature,
      ];

      return [
        'daytime' => $daytimeForecast,
        'overnight' => $overnightForecast,
      ];

    }, $dayNightPairs);

    return array_values($periods);
  }

}
