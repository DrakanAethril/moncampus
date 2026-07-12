<?php

namespace App\Form;

// Single source of truth for the platform's default per-file upload size limit, so every FileType
// field stays consistent (and displays the same limit to the user) unless a field has a genuine
// reason to differ - e.g. AvatarUploadType deliberately stays smaller, since a profile picture
// never needs 20M. frankenphp/conf.d/10-app.ini's upload_max_filesize/post_max_size must stay
// above MAX_SIZE, otherwise PHP silently truncates the upload before this constraint ever runs.
final class FileUploadDefaults
{
    public const string MAX_SIZE = '20M';
    public const string MAX_SIZE_HELP_KEY = 'fileUploadMaxSizeHelpText';
}
