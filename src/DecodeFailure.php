<?php

namespace IdempotentExport;

/**
 * Sentinel returned by Encoder when unserialize fails on something that
 * looked serialized. Kept as its own type so an instanceof check can't be
 * fooled by user data shaped like our marker.
 */
class DecodeFailure {
}
