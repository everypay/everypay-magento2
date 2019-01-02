function epcallback(message){

    let epToken = message.token;
    checkoutConfig.payment.everypay.token = epToken;

    var paybutton = document.getElementById('epPlaceOrder');
    paybutton.click();
}