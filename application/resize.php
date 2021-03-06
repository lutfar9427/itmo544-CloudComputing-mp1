<?php
################################################
# Illinois Institute of Technology
# ITMO 544 Cloud Computing - Mini Project 1 
#
# Student: Guillermo de la Puente
#          https://github.com/gpuenteallott
#
# resize.php
# - read the queue message body into a variable.
# - Select the Item from your SimpleDb that matches the ID
# - parse the response object
# - use S3 getObject - store in the /tmp directory
# - Pass the downloaded object to the php gd library and add a water mark - image provided
# - Upload the newly rendered image back to the S3 bucket the original came from
# - Update the SimpleDB object giving the URI of the S3 object to the 'finishedurl' Attribute Value
#   Pair in Simple DB
################################################

// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';
#header("Content-type: text/plain; charset=utf-8");

use Aws\SimpleDb\SimpleDbClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Common\Aws;
use Aws\SimpleDb\Exception\InvalidQueryExpressionException;

//aws factory
$aws = Aws::factory('vendor/aws/aws-sdk-php/src/Aws/Common/Resources/custom-config.php');

// Instantiate the S3 client with your AWS credentials and desired AWS region
$client = $aws->get('S3');

$sdbclient = $aws->get('SimpleDb');

$sqsclient = $aws->get('Sqs');

# Read the name file
# Name is the resources identifier in AWS for this system
$NAME = file_get_contents("name.txt");
$NAME_SDB = str_replace("-", "", $NAME)."sdb";

// sqs message body will contain the id
$mbody="";
// sqs receipt handle will contain an id necessary to remove the sqs message later
$receiptHandle="";

#####################################################
# SQS Read the queue for some information -- we will consume the queue later
#####################################################

# The URL must be obtained
# Obtain the SQS url for the given name
$sqs_queue_url = $sqsclient->getQueueUrl(array(
    'QueueName' => "$NAME-sqs",
));
//var_export($sqs_queue_url->getkeys());
$sqs_queue_url = $sqs_queue_url['QueueUrl'];


$result = $sqsclient->receiveMessage(array(
    // QueueUrl is required
    'QueueUrl' => $sqs_queue_url,
    'MaxNumberOfMessages' => 1, 
));

######################################
# Probably need some logic in here to handle delays
######################################

foreach ($result->getPath('Messages/*/Body') as $messageBody) {
    // Do something with the message
    //echo "SQS: " . $messageBody . "\n";
    $mbody=$messageBody;
}
foreach ($result->getPath('Messages/*/ReceiptHandle') as $receiptHandle2) {
    // Do something with the message
   // echo "SQS receipt Handle: " . $receiptHandle2 . "\n";
    $receiptHandle=$receiptHandle2;
}

if ( $mbody === "" ) {
    ?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css"/>
    <title>ITMO 544 - Resize.php</title>
    
</head>
<body>
    <div id="main">
        <header>
            <h1>Picture Uploader</h1>

            <p>A mini project for ITMO 544 - Cloud Computing</p>
            <p>Illinois Institute of Technology</p>
            <p><a href="https://github.com/gpuenteallott/itmo544-CloudComputing-mp1">Project in GitHub</a></p>
        </header>

        <h2>Impatient!</h2>
        <p>The value in SQS isn't readable yet because of its eventually consistent behaviour.</p>
        <p>You can refresh this view in a few seconds to try again</p>

         <p class="next"><a href="" onClick="window.location.reload">Refresh</a></p>
    </div>
</body>
</html>
<?php
    exit;
}

##############################################
# Select from SimpleDB element where id = the id in the Queue
##############################################
$exp = "select * from $NAME_SDB where id = '$mbody'";
//echo "\n".$exp."\n";

try {
$iterator = $sdbclient->getIterator('Select', array(
    'SelectExpression' => $exp,
));
} catch(InvalidQueryExpression $i) {
 //echo 'Invalid query: '. $i->getMessage() . "\n";
}

####################################################################
# Declare some variables as place holders for the select object
####################################################################
$email = '';
$rawurl = '';
$finishedurl = '';
$bucket = '';
$id = '';
$phone = '';
$filename = '';
$localfilename = ""; // this is a local variabel used to store the content of the s3 object
###################################################################
# Now we are going to loop through the response object to get the 
# values of the returned object
##################################################################
foreach ($iterator as $item) {
    //echo "Item: " . $item['Name'] . "\n";
 #var_export($item['Attributes']);
     foreach ($item['Attributes'] as $attribute) {
            #if ($attribute['Name'] == 'email') {
    	#  echo "Email Value: " . $attribute['Value'] . "\n";
        switch ($attribute['Name']) {
            case "id": 
                //echo "id Value is: ". $attribute['Value']."\n";
                $id = $attribute['Value'];
                break;
            case "email":
                //echo "Email Value is: ". $attribute['Value']."\n";
                $email = $attribute['Value']; 
                break;
            case "bucket":
                //echo "Bucket Value is: ". $attribute['Value']."\n";
                $bucket = $attribute['Value'];
                break;
           case "rawurl":
                //echo "RawURL Value is: ". $attribute['Value']."\n";
                $rawurl = $attribute['Value'];
                break;
           # This case is wrong
           # The value is empty for finishedurl, which causes problems when getting its value
           #case "finishedurl":
           #     echo "Finished URL Value is: ". $attribute['Value']."\n";
           #     $finishedurl = $attribute['Value'];
           #     break;
           case "filename":
                //echo "Filename Value is: ". $attribute['Value']."\n";
                $filename = $attribute['Value'];
                break;
           case "phone":
                //echo "Phone Value is: ". $attribute['Value']."\n";
                $phone = $attribute['Value'];
                break;
           #default: 
           #     echo "Unable to figure out - " . $attribute['Name'] ." = " . $attribute['Value'];

        }
    }
}

###########################################################################
#  Now that you have the URI returned in the S3 object you can use wget -
# http://en.wikipedia.org/wiki/Wget to pull down the image from the S3 url
# then we add the stamp on the picture save the image out and then reupload
# it to S3 and then update the item in SimpleDb  S3 has a prefix URL which can
# be hard coded https://s3.amazonaws.com
############################################################################
$s3urlprefix = 'https://s3.amazonaws.com/';
$localfilename = "./tmp/$filename";
//echo "Getting object $filename from bucket $bucket . Saving at $localfilename";
$result = $client->getObject(array(
    'Bucket' => $bucket,
    'Key'    => $filename,
    'SaveAs' => $localfilename,
));
//echo "Done";
############################################################################
#  Now that we have called the s3 object and downloaded (getObject) the file
# to our local system - lets pass the file to our watermark library 
# http://en.wikipedia.org/wiki/Watermark -- using a function  
###########################################################################
addStamp($localfilename);


############################################################################
# S3
# Upload the new image to the bucket
############################################################################

$newFilename = str_replace(".jpg", "_new.jpg", $filename);

$result = $client->putObject(array(
    'ACL'        => 'public-read',
    'Bucket'     => $bucket,
    'Key'        => $newFilename,
    'SourceFile' => $localfilename,
    'Metadata'   => array(
        'timestamp' => time(),
        'md5' =>  md5_file($localfilename),
    )
));
$client->waitUntilObjectExists(array(
    'Bucket' => $bucket,
    'Key'    => $newFilename
));

//var_export($result->getkeys());
$newUrl= $result['ObjectURL'];

############################################################################
# SDB
# Update the record of this object to include the finished url information
############################################################################

$itemName = 'images-'.$id;

$result = $sdbclient->putAttributes(array(

    'DomainName' => "$NAME_SDB",
    'ItemName' =>$itemName ,
    'Attributes' => array(
         array(
            'Name' => 'finishedurl',
            'Value' => $newUrl,
            'Replace' => true
        ),
        array(
            'Name' => 'receiptHandle',
            'Value' => $receiptHandle,
            'Replace' => true
        ),
    ),
));


#########################################################################
# PHP function for adding a "stamp" or watermark through the php gd library
#########################################################################
function addStamp($image)
{
    // Load the stamp and the photo to apply the watermark to
    // http://php.net/manual/en/function.imagecreatefromgif.php
    $stamp = imagecreatefromgif('./happy_trans.gif');
    $im = imagecreatefromjpeg($image);
    
    // Set the margins for the stamp and get the height/width of the stamp image
    $marge_right = 10;
    $marge_bottom = 10;
    $sx = imagesx($stamp);
    $sy = imagesy($stamp);

    // Copy the stamp image onto our photo using the margin offsets and the photo 
    // width to calculate positioning of the stamp. 
    imagecopy($im, $stamp, imagesx($im) - $sx - $marge_right, imagesy($im) - $sy - $marge_bottom, 0, 0, imagesx($stamp), imagesy($stamp));

   
    # (Commented) This cannot be here, problems with outputing resize.php as HTML
    # header('Content-type: image/png');

    // Output and free memory
    imagepng($im, $image);
    imagedestroy($im);
    imagedestroy($stamp);

} // end of function

?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css"/>
    <title>ITMO 544 - Resize.php</title>
</head>
<body>
    <div id="main">
        <header>
            <h1>Picture Uploader</h1>

            <p>A mini project for ITMO 544 - Cloud Computing</p>
            <p>Illinois Institute of Technology</p>
            <p><a href="https://github.com/gpuenteallott/itmo544-CloudComputing-mp1">Project in GitHub</a></p>
        </header>

        <h2>2. Stamp step</h2>
        <p>What have you done?!</p>
        <ol>
            <li>Value retrieved from the SQS message</li>
            <li>Information about the image retrieved from SimpleDB</li>
            <li>Image downloaded from S3</li>
            <li>Funny face stamp added</li>
            <li>New image uploaded to S3</li>
        </ol>

        <div class="picContainer">
            <h3>Local image in server</h3>
            <p class="link"><? echo $localfilename ?></p>
            <img src="<? echo $localfilename ?>" />
        </div>
        <div class="picContainer">
            <h3>Remote image in S3</h3>
            <p class="link"><? echo $newUrl ?></p>
            <img src="<? echo $newUrl ?>" />
        </div>
        <div class="picContainer">
            <h3>Previous image in S3</h3>
            <p class="link"><? echo $s3urlprefix.'/'.$bucket.'/'.$filename ?></p>
            <img src="<? echo $s3urlprefix.'/'.$bucket.'/'.$filename ?>" />
        </div>  
        <br/>
        <h3>1. Process step</h3>
        <p>What did you do?!</p>
        <ol>
            <li>Picture uploaded to S3 bucket <? echo $bucket ?></li>
            <li>Information recorded in SimpleDB</li>
            <li>SQS message added to recover the information in the following step</li>
            <li>Phone number <? echo $phone ?> subscribed</li>
            <li>Email <? echo $email ?> suscribed</li>
        </ol>

         <p class="next">Continue to next step --> <a href="cleanup.php">Clean Up</a></p>
    </div>
</body>
</html>