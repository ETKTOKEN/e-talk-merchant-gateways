(function () {
  'use strict';

  if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) return;
  const el = window.wp.element.createElement;
  const useState = window.wp.element.useState,
    useEffect = window.wp.element.useEffect,
    useRef = window.wp.element.useRef;
  const createPortal = window.wp.element.createPortal || window.ReactDOM && window.ReactDOM.createPortal;
  const register = window.wc.wcBlocksRegistry.registerPaymentMethod;
  const getSetting = window.wc.wcSettings.getSetting;
  ['v1', 'bnpl'].forEach(function (channel) {
    const name = 'etalk_' + channel,
      settings = getSetting(name + '_data', {});
    if (!settings.admin_preview) return;
    const title = String(settings.title || (channel === 'bnpl' ? 'E-TALK BNPL' : 'E-TALK V1 Card'));
    const logo = String(settings.logo || '');
    const label = el('span', null, logo ? el('img', {
      src: logo,
      alt: '',
      width: 28,
      height: 28,
      style: {
        verticalAlign: 'middle',
        marginRight: 8
      }
    }) : null, title);
    function Content(props) {
      const [wallet, setWallet] = useState(''),
        [ccv, setCcv] = useState(''),
        [otp, setOtp] = useState('');
      const [stage, setStage] = useState(''),
        [busy, setBusy] = useState(false);
      const [bnplEmail, setBnplEmail] = useState(''),
        [bnplPassword, setBnplPassword] = useState(''),
        [bnplOtp, setBnplOtp] = useState('');
      const [bnplStage, setBnplStage] = useState('login'),
        [bnplBusy, setBnplBusy] = useState(false),
        [bnplMsg, setBnplMsg] = useState('');
      const [panelOpen, setPanelOpen] = useState(false);
      const dialogRef = useRef(null),
        triggerRef = useRef(null);
      const ev = props.eventRegistration || {},
        emit = props.emitResponse || {};
      useEffect(function () {
        const onSetup = ev.onPaymentSetup || ev.onPaymentProcessing;
        if (typeof onSetup !== 'function') return;
        return onSetup(function () {
          const error = msg => ({
            type: emit.responseTypes ? emit.responseTypes.ERROR : 'error',
            message: msg
          });
          if (channel === 'bnpl') {
            if (bnplStage !== 'eligible') return error('Complete E-TALK login, OTP verification and eligibility before paying.');
            return {
              type: emit.responseTypes ? emit.responseTypes.SUCCESS : 'success',
              meta: {
                paymentMethodData: {}
              },
              paymentMethodData: {}
            };
          }
          if (!/^0x[a-fA-F0-9]{40}$/.test(wallet)) return error('Enter a valid WebChain wallet address.');
          if (!/^[0-9]{3}$/.test(ccv)) return error('Enter the three-digit V1 Card CCV.');
          if (!/^[a-f0-9]{64}$/.test(otp)) return error('Enter the full 64-character hexadecimal code from the E-TALK email (not a 6-digit code).');
          if (stage !== 'sent') return error('Request your email verification code first.');
          const payload = {
            etalk_v1_wallet: wallet,
            etalk_v1_ccv: ccv,
            etalk_v1_otp: otp
          };
          // WooCommerce Blocks' payment setup transports paymentMethodData in meta.
          // Retain the top-level field for versions accepting the newer shape.
          return {
            type: emit.responseTypes ? emit.responseTypes.SUCCESS : 'success',
            meta: {
              paymentMethodData: payload
            },
            paymentMethodData: payload
          };
        });
      }, [ev.onPaymentSetup, ev.onPaymentProcessing, wallet, ccv, otp, stage, bnplStage]);
      useEffect(function () {
        if (!panelOpen || typeof document === 'undefined') return;
        const previousFocus = document.activeElement;
        const priorOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const dialog = dialogRef.current;
        if (dialog) {
          const first = dialog.querySelector('input,button:not([disabled]),[tabindex="0"]');
          if (first) first.focus();
        }
        function handleKey(e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            setPanelOpen(false);
            return;
          }
          if (e.key !== 'Tab' || !dialog) return;
          const nodes = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')).filter(n => n.getClientRects().length);
          if (!nodes.length) return;
          if (e.shiftKey && document.activeElement === nodes[0]) {
            e.preventDefault();
            nodes[nodes.length - 1].focus();
          } else if (!e.shiftKey && document.activeElement === nodes[nodes.length - 1]) {
            e.preventDefault();
            nodes[0].focus();
          }
        }
        document.addEventListener('keydown', handleKey);
        return function () {
          document.removeEventListener('keydown', handleKey);
          document.body.style.overflow = priorOverflow;
          const target = triggerRef.current || previousFocus;
          if (target && typeof target.focus === 'function') target.focus();
        };
      }, [panelOpen]);
      function paymentPanel(fields) {
        const ready = channel === 'bnpl' ? bnplStage === 'eligible' : stage === 'sent' && /^[a-f0-9]{64}$/.test(otp);
        const button = el('button', {
          type: 'button',
          className: 'etalk-pay-open',
          ref: triggerRef,
          onClick: () => setPanelOpen(true),
          'aria-haspopup': 'dialog',
          'aria-expanded': panelOpen
        }, ready ? 'Payment details verified · Review' : 'Set up ' + title);
        const hint = el('p', {
          className: 'etalk-pay-hint',
          role: 'status'
        }, ready ? 'Verification complete. You can place your order.' : 'Securely verify your payment details before placing the order.');
        const panel = el('div', {
          className: 'etalk-pay-overlay',
          onMouseDown: e => {
            if (e.target === e.currentTarget) setPanelOpen(false);
          }
        }, el('section', {
          className: 'etalk-pay-dialog',
          role: 'dialog',
          'aria-modal': 'true',
          'aria-label': title + ' payment verification',
          ref: dialogRef
        }, el('div', {
          className: 'etalk-pay-grip',
          'aria-hidden': 'true'
        }), el('header', {
          className: 'etalk-pay-heading'
        }, el('div', null, el('strong', null, title), el('p', null, 'Secure payment verification')), el('button', {
          type: 'button',
          className: 'etalk-pay-close',
          onClick: () => setPanelOpen(false),
          'aria-label': 'Close payment details'
        }, '×')), el('div', {
          className: 'etalk-pay-body'
        }, fields), el('footer', {
          className: 'etalk-pay-footer'
        }, el('button', {
          type: 'button',
          className: 'etalk-pay-done',
          onClick: () => setPanelOpen(false)
        }, ready ? 'Continue to place order' : 'Return to checkout'))));
        if (typeof document === 'undefined' || typeof createPortal !== 'function') return el('div', {
          className: 'etalk-pay-inline'
        }, fields);
        return el('div', {
          className: 'etalk-pay-launch'
        }, button, hint, panelOpen ? createPortal(panel, document.body) : null);
      }
      function bnplCall(action, fields) {
        setBnplBusy(true);
        setBnplMsg('Contacting E-TALK…');
        const d = new URLSearchParams(Object.assign({
          action: action,
          nonce: String(settings.bnpl_nonce || '')
        }, fields));
        return fetch(String(settings.otp_ajax || ''), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
          },
          body: d.toString(),
          cache: 'no-store'
        }).then(async r => {
          const j = await r.json();
          if (!r.ok || !j.success) throw new Error(j.data && j.data.code || 'request_failed');
          return j.data || {};
        }).catch(e => {
          setBnplMsg('E-TALK rejected: ' + e.message);
          throw e;
        }).finally(() => setBnplBusy(false));
      }
      if (channel === 'bnpl') return paymentPanel(el('div', {
        style: {
          padding: '12px 0',
          lineHeight: 1.7
        }
      }, el('p', {
        className: 'etalk-bnpl-intro'
      }, 'Pay with E-TALK BNPL: sign in, verify your email code, and check eligibility for your order.'), bnplStage === 'login' ? el('div', null, el('label', {
        style: {
          display: 'block'
        }
      }, 'E-TALK account email', el('input', {
        type: 'email',
        value: bnplEmail,
        autoComplete: 'email',
        style: {
          display: 'block',
          width: '100%',
          maxWidth: 480
        },
        onChange: e => setBnplEmail(e.target.value.trim())
      })), el('label', {
        style: {
          display: 'block'
        }
      }, 'E-TALK password', el('input', {
        type: 'password',
        value: bnplPassword,
        autoComplete: 'off',
        style: {
          display: 'block',
          width: '100%',
          maxWidth: 480
        },
        onChange: e => setBnplPassword(e.target.value)
      })), el('button', {
        type: 'button',
        disabled: bnplBusy || !bnplEmail || !bnplPassword,
        onClick: () => bnplCall('etalk_bnpl_auth_start', {
          email: bnplEmail,
          password: bnplPassword
        }).then(() => {
          setBnplPassword('');
          setBnplStage('otp');
          setBnplMsg('OTP sent. Enter your six-digit code.');
        }).catch(() => {
          setBnplPassword('');
        })
      }, 'Send BNPL OTP')) : null, bnplStage === 'otp' ? el('div', null, el('label', {
        style: {
          display: 'block'
        }
      }, 'Six-digit OTP', el('input', {
        type: 'text',
        inputMode: 'numeric',
        autoComplete: 'one-time-code',
        maxLength: 6,
        value: bnplOtp,
        onChange: e => setBnplOtp(e.target.value.replace(/[^0-9]/g, ''))
      })), el('button', {
        type: 'button',
        disabled: bnplBusy || bnplOtp.length !== 6,
        onClick: () => bnplCall('etalk_bnpl_auth_verify', {
          otp: bnplOtp
        }).then(() => {
          setBnplOtp('');
          setBnplMsg('Identity verified. Checking order eligibility…');
          return bnplCall('etalk_bnpl_eligibility_check', {});
        }).then(d => {
          if (d.eligible) {
            setBnplStage('eligible');
            setBnplMsg('Eligible for ' + d.amount + ' USD. Place your order.');
          } else {
            setBnplStage('verify');
            setBnplMsg('Not eligible: ' + (d.reason || 'unknown'));
          }
        }).catch(() => {})
      }, 'Verify OTP')) : null, bnplStage === 'verify' ? el('button', {
        type: 'button',
        disabled: bnplBusy,
        onClick: () => bnplCall('etalk_bnpl_eligibility_check', {}).then(d => {
          if (d.eligible) {
            setBnplStage('eligible');
            setBnplMsg('Eligible for ' + d.amount + ' USD. You can place the order.');
          } else {
            setBnplMsg('Not eligible: ' + (d.reason || 'unknown'));
          }
        }).catch(() => {})
      }, 'Check eligibility') : null, bnplStage === 'eligible' ? el('p', {
        role: 'status',
        'aria-live': 'polite'
      }, '✓ Verified and eligible. Your payment reference is created automatically when you place the order.') : null, el('p', {
        role: 'status',
        'aria-live': 'polite'
      }, bnplMsg), el('small', null, 'Your payment uses IKB and creates a 30-day C-Lock. Do not submit again if the payment outcome is uncertain.')));
      function requestCode() {
        if (!/^0x[a-fA-F0-9]{40}$/.test(wallet) || !/^[0-9]{3}$/.test(ccv)) {
          setStage('Enter a valid wallet and three-digit CCV.');
          return;
        }
        setBusy(true);
        setStage('Requesting verification code…');
        const data = new URLSearchParams({
          action: 'etalk_v1_blocks_request_otp',
          nonce: String(settings.otp_nonce || ''),
          wallet: wallet,
          ccv: ccv
        });
        fetch(String(settings.otp_ajax || ''), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
          },
          body: data.toString(),
          cache: 'no-store'
        }).then(async r => {
          const j = await r.json();
          if (!r.ok || !j.success) throw new Error(j.data && j.data.code || 'request_failed');
          return j;
        }).then(() => {
          setStage('sent');
          setOtp('');
        }).catch(e => setStage('Verification request rejected: ' + e.message)).finally(() => setBusy(false));
      }
      const fieldStyle = {
        width: '100%',
        maxWidth: 480,
        padding: '10px',
        marginTop: 5
      };
      return paymentPanel(el('div', {
        className: 'etalk-v1-live-fields',
        style: {
          padding: '12px 0',
          lineHeight: 1.7
        }
      }, el('p', null, 'Enter your V1 Card details and request the verification code before placing your order.'), el('label', {
        style: {
          display: 'block',
          marginBottom: 12
        }
      }, 'WebChain wallet address', el('input', {
        type: 'text',
        name: 'etalk_v1_wallet_display',
        value: wallet,
        placeholder: '0x…',
        autoComplete: 'off',
        style: fieldStyle,
        onChange: e => {
          setWallet(e.target.value.trim());
          setStage('');
          setOtp('');
        }
      })), el('label', {
        style: {
          display: 'block',
          marginBottom: 12
        }
      }, 'V1 Card CCV (3 digits)', el('input', {
        type: 'password',
        name: 'etalk_v1_ccv_display',
        value: ccv,
        maxLength: 3,
        inputMode: 'numeric',
        autoComplete: 'off',
        style: fieldStyle,
        onChange: e => {
          setCcv(e.target.value);
          setStage('');
          setOtp('');
        }
      })), el('button', {
        type: 'button',
        disabled: busy,
        onClick: requestCode,
        style: {
          padding: '10px 16px',
          marginBottom: 10
        }
      }, busy ? 'Requesting…' : 'Send verification code'), el('p', {
        role: 'status',
        'aria-live': 'polite'
      }, stage === 'sent' ? 'Code sent to the cardholder’s registered email. Expires in 5 minutes.' : stage), el('label', {
        style: {
          display: 'block',
          marginBottom: 10
        }
      }, 'Email verification code (64 characters)', el('input', {
        type: 'password',
        value: otp,
        maxLength: 64,
        autoComplete: 'one-time-code',
        style: fieldStyle,
        onChange: e => setOtp(e.target.value.replace(/\s+/g, '').toLowerCase())
      })), el('small', null, 'Payment is submitted only when you place your order with a valid code. No card credentials are saved in order metadata.')));
    }
    register({
      name,
      paymentMethodId: name,
      gatewayId: name,
      label,
      ariaLabel: title,
      content: el(Content),
      edit: el(Content),
      canMakePayment: () => settings.admin_preview === true,
      supports: {
        features: Array.isArray(settings.supports) ? settings.supports : ['products']
      }
    });
  });
})();
