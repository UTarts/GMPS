<?php
// Default SEO Values (Fallback)
if (!isset($pageTitle)) $pageTitle = "Govind Madhav Public School | Best School in Pratapgarh";
if (!isset($pageDesc))  $pageDesc  = "Govind Madhav Public School (GMPS) provides world-class CBSE education from Nursery to Class 12 in Gondey, Pratapgarh. Excellence in academics, sports, and holistic development.";
$currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">

<title><?php echo $pageTitle; ?></title>
<meta name="description" content="<?php echo $pageDesc; ?>">
<meta name="keywords" content="Govind Madhav Public School, GMPS Pratapgarh, Best School in Gondey, CBSE School Pratapgarh, English Medium School, Education Uttar Pradesh, Best School Near Me, Quality Education, School Admissions">
<meta name="author" content="Govind Madhav Public School">
<link rel="canonical" href="<?php echo $currentUrl; ?>">

<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo $currentUrl; ?>">
<meta property="og:title" content="<?php echo $pageTitle; ?>">
<meta property="og:description" content="<?php echo $pageDesc; ?>">
<meta property="og:image" content="https://govindmadhav.com/GMPSimages/logo.png">

<meta property="twitter:card" content="summary_large_image">
<meta property="twitter:title" content="<?php echo $pageTitle; ?>">
<meta property="twitter:description" content="<?php echo $pageDesc; ?>">
<meta property="twitter:image" content="https://govindmadhav.com/GMPSimages/logo.png">

<link rel="icon" type="image/png" href="GMPSimages/logo.png">
<link rel="apple-touch-icon" href="GMPSimages/logo.png">

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "School",
  "name": "Govind Madhav Public School",
  "image": "https://govindmadhav.com/GMPSimages/logo.png",
  "@id": "https://govindmadhav.com",
  "url": "https://govindmadhav.com",
  "telephone": "+919984661166",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Pratapgarh-Sultanpur highway, opposite reliance petrol pump, Gondey",
    "addressLocality": "Pratapgarh",
    "addressRegion": "Uttar Pradesh",
    "postalCode": "230403",
    "addressCountry": "IN"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": 25.9287, 
    "longitude": 81.9967
  },
  "sameAs": [
    "https://www.facebook.com/share/15vCcE1Qsv/",
    "https://youtube.com/@govindmadhav2018"
  ]
}
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
<link rel="stylesheet" href="styles.css?v=2.0">