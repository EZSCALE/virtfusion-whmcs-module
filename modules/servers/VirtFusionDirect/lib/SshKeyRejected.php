<?php

namespace WHMCS\Module\Server\VirtFusionDirect;

/**
 * A customer-supplied SSH key or key name was refused.
 *
 * The message is written for the customer and is the only validation text
 * SshKeys::clientError() passes through to the browser.
 */
class SshKeyRejected extends \InvalidArgumentException {}
