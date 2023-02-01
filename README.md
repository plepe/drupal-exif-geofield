# drupal-exif-geofield
Import exif lon/lat data into a geofield 

## Installation
```
cd web/modules/custom
git clone https://github.com/plepe/drupal-exif-geofield exif_geofield
drush en exif_geofield
```

## Configuration
* Add an Image field to an Entity Type (Content Type, Media, ...)
* Add a Geofield to the Entity Type
* On Manage Form Display, choose "location as WKT from image" as Widget. In the settings, choose the correct image field from which to retrieve data

When uploading an image, the location should now be read into the Geofield.
